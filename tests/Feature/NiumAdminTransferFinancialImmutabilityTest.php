<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\NiumComplianceEvent;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumService;
use App\Services\Wallet\LedgerService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Throwable;

/** Disposable PostgreSQL only; provider service-port stubs never send HTTP. */
final class NiumAdminTransferFinancialImmutabilityTest extends DynamicConfirmationDatabaseTestCase
{
    private const DENIED = 'Nium transfers with financial history or bound authorization cannot be edited or deleted through generic Admin CRUD.';

    private IntegrationProvider $provider;
    private User $owner;
    private User $admin;
    private Beneficiary $beneficiary;
    private Balance $balance;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'wallet.ledger.enabled' => true,
            'wallet.transfer_controls.require_admin_approval' => false,
            'services.nium.base_url' => 'https://gateway.nium.test',
            'services.nium.client_id' => 'patch259-client',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'fixture-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'fixture-partner-key',
            'services.nium.payout_fx_enabled' => false,
        ]);
        $this->provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium fixture', 'status' => 'active']);
        $this->owner = User::factory()->create(['status' => 'active', 'kyc_status' => 'verified']);
        $this->owner->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->owner->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'business', 'legal_name' => 'Fixture HK Company',
            'business_name' => 'Fixture HK Company', 'registered_country_code' => 'HK',
            'country_code' => 'HK', 'address_line1' => '1 Fixture Road', 'city' => 'Hong Kong',
            'postal_code' => '999077', 'metadata' => ['nium_region' => 'HK'],
        ]);
        $this->owner->providerAccounts()->create([
            'provider_id' => $this->provider->id, 'external_customer_id' => 'patch259-customer',
            'external_account_id' => 'patch259-wallet', 'status' => 'active', 'provider_status' => 'clear',
            'reconciliation_status' => 'reconciled', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $this->beneficiary = Beneficiary::query()->create([
            'user_id' => $this->owner->id, 'provider_id' => $this->provider->id,
            'external_beneficiary_id' => 'patch259-payee', 'beneficiary_type' => 'business',
            'full_name' => 'Fixture HK Payee', 'country_code' => 'HK', 'currency' => 'USD',
            'payout_method' => 'SWIFT', 'status' => 'active', 'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
        ]);
        $this->balance = Balance::query()->create([
            'user_id' => $this->owner->id, 'provider_id' => $this->provider->id,
            'external_account_id' => 'patch259-wallet', 'currency' => 'USD',
            'available_balance' => '1000', 'reserved_balance' => '0', 'ledger_balance' => '1000', 'as_of' => now(),
        ]);
        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->roles()->create(['role_code' => 'admin']);
        $this->adminToken = $this->token($this->admin);
    }

    public static function protectedMutations(): array
    {
        $fields = [
            'source_amount', 'owner', 'provider', 'source_currency', 'target_currency', 'target_amount',
            'beneficiary', 'fee_amount', 'fee_currency', 'fx_rate', 'transfer_type', 'transfer_no',
            'purpose_code', 'reference_text', 'client_reference', 'status', 'external_transfer_id',
            'external_payment_id', 'submitted_at', 'completed_at', 'failure_code', 'failure_reason',
            'balance_binding', 'payout_method', 'erase_receipt', 'unvalidated_fillable_fields', 'empty_payload',
        ];

        return array_combine($fields, array_map(static fn ($field) => [$field], $fields));
    }

    #[DataProvider('protectedMutations')]
    public function test_partial_or_mass_assignment_payload_cannot_edit_a_held_transfer(string $field): void
    {
        $transfer = $this->transfer();
        app(LedgerService::class)->reserveTransfer($transfer);
        $payload = match ($field) {
            'owner' => ['user_id' => User::factory()->create()->id],
            'provider' => ['provider_id' => $this->foreignProvider()->id],
            'source_amount' => ['source_amount' => '200'],
            'source_currency' => ['source_currency' => 'EUR'],
            'target_currency' => ['target_currency' => 'EUR'],
            'target_amount' => ['target_amount' => '90'],
            'beneficiary' => ['beneficiary_id' => null],
            'fee_amount' => ['fee_amount' => '5'],
            'fee_currency' => ['fee_currency' => 'EUR'],
            'fx_rate' => ['fx_rate' => '0.9'],
            'transfer_type' => ['transfer_type' => 'bank'],
            'transfer_no' => ['transfer_no' => 'REPLACEMENT-259'],
            'purpose_code' => ['purpose_code' => 'IR004'],
            'reference_text' => ['reference_text' => 'Replacement instruction'],
            'client_reference' => ['client_reference' => 'replacement-client-reference'],
            'status' => ['status' => 'completed'],
            'external_transfer_id' => ['external_transfer_id' => 'replacement-reference'],
            'external_payment_id' => ['external_payment_id' => 'replacement-payment'],
            'submitted_at' => ['submitted_at' => now()->toISOString()],
            'completed_at' => ['completed_at' => now()->toISOString()],
            'failure_code' => ['failure_code' => 'replacement-code'],
            'failure_reason' => ['failure_reason' => 'Replacement reason'],
            'balance_binding' => ['raw_data' => ['wallet' => ['balance_id' => $this->balance->id + 1]]],
            'payout_method' => ['raw_data' => ['nium' => ['payout' => ['payoutMethod' => 'LOCAL']]]],
            'erase_receipt' => ['raw_data' => []],
            'unvalidated_fillable_fields' => ['provider_operation_key' => null, 'fx_quote_id' => null,
                'provider_status' => null, 'provider_status_at' => null, 'compliance_review_required' => false],
            'empty_payload' => [],
        };
        $before = $this->snapshot($transfer);

        $this->withToken($this->adminToken)->patchJson($this->url($transfer), $payload)
            ->assertUnprocessable()->assertJsonPath('message', self::DENIED);

        $this->assertSame($before, $this->snapshot($transfer));
    }

    public static function financialEvidence(): array
    {
        $cases = ['hold_without_receipt', 'debit', 'release', 'semantic_journal', 'canonical_journal',
            'claim', 'external_transfer', 'external_payment', 'submitted_at', 'provider_status_at',
            'provider_status', 'completed_at', 'failure_evidence', 'ambiguous', 'terminal',
            'transaction', 'request_log', 'compliance_event', 'quote', 'approval', 'raw_receipt', 'raw_provider_result'];

        return array_combine($cases, array_map(static fn ($case) => [$case], $cases));
    }

    #[DataProvider('financialEvidence')]
    public function test_persisted_evidence_prevents_edit_and_delete_even_without_active_hold_or_matching_status(string $case): void
    {
        $transfer = $this->transfer();
        $this->addEvidence($transfer, $case);
        $before = $this->snapshot($transfer);

        $this->withToken($this->adminToken)->patchJson($this->url($transfer), ['status' => 'draft', 'raw_data' => null])
            ->assertUnprocessable()->assertJsonPath('message', self::DENIED);
        $this->deleteJson($this->url($transfer))->assertUnprocessable()->assertJsonPath('message', self::DENIED);

        $this->assertSame($before, $this->snapshot($transfer));
    }

    public function test_nium_journal_still_protects_a_previously_corrupted_provider_identity(): void
    {
        $transfer = $this->transfer();
        app(LedgerService::class)->reserveTransfer($transfer);
        $transfer->refresh()->update(['provider_id' => $this->foreignProvider()->id, 'raw_data' => null]);
        $before = $this->snapshot($transfer);

        $this->withToken($this->adminToken)->putJson($this->url($transfer), ['source_amount' => '200'])
            ->assertUnprocessable()->assertJsonPath('message', self::DENIED);
        $this->deleteJson($this->url($transfer))->assertUnprocessable()->assertJsonPath('message', self::DENIED);

        $this->assertSame($before, $this->snapshot($transfer));
    }

    public function test_submission_claim_cannot_be_erased_with_financial_raw_data_or_fillable_metadata(): void
    {
        $transfer = $this->transfer([
            'status' => 'submitting', 'provider_operation_key' => 'patch259-durable-claim',
            'raw_data' => ['provider_operation_key' => 'patch259-durable-claim',
                'nium' => ['payout' => ['payoutMethod' => 'SWIFT']]],
        ]);
        $before = $this->snapshot($transfer);

        $this->withToken($this->adminToken)->patchJson($this->url($transfer), [
            'status' => 'draft', 'provider_operation_key' => null, 'provider_status_at' => null,
            'raw_data' => ['wallet' => ['balance_id' => 999], 'nium' => ['payout' => ['payoutMethod' => 'LOCAL']]],
        ])->assertUnprocessable()->assertJsonPath('message', self::DENIED);

        $this->assertSame($before, $this->snapshot($transfer));
    }

    public static function prefinancialStatuses(): array
    {
        return ['draft' => ['draft'], 'awaiting approval' => ['approval_required'], 'no recorded authorization' => ['approved']];
    }

    #[DataProvider('prefinancialStatuses')]
    public function test_unbound_prefinancial_update_and_delete_remain_available(string $status): void
    {
        $transfer = $this->transfer(['status' => $status]);
        $this->withToken($this->adminToken)->patchJson($this->url($transfer), [
            'source_amount' => '200.00000001', 'reference_text' => 'Updated before financial binding',
            'raw_data' => ['source' => 'Admin fixture'],
        ])->assertOk()->assertJsonPath('source_amount', '200.00000001');
        $this->assertSame('200.00000001', $transfer->fresh()->source_amount);
        $this->assertSame('Updated before financial binding', $transfer->fresh()->reference_text);
        // Admin validation supports raw_data.rate_id, not the unvalidated source key.
        $this->assertNull($transfer->fresh()->raw_data);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('1000.00000000', $this->balance->fresh()->available_balance);

        $this->deleteJson($this->url($transfer))->assertNoContent();
        $this->assertNull($transfer->fresh());
    }

    public function test_hk_customer_instruction_admin_approval_and_service_reconciliation_still_work(): void
    {
        config(['wallet.transfer_controls.require_admin_approval' => true,
            'wallet.transfer_controls.approval_threshold_amount' => 0]);
        $this->stubProvider();
        $created = $this->withToken($this->token($this->owner))->postJson('/api/user/users/'.$this->owner->id.'/transfers', [
            'provider_id' => $this->provider->id, 'beneficiary_id' => $this->beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => '100', 'purpose_code' => 'IR01811', 'reference_text' => 'PATCH259-HK',
            'client_reference' => 'PATCH259-HK-CUSTOMER',
        ])->assertCreated()->assertJsonPath('status', 'approval_required');
        $transfer = Transfer::query()->findOrFail($created->json('id'));

        $this->withToken($this->adminToken)->postJson($this->url($transfer).'/approve')
            ->assertOk()->assertJsonPath('transfer.status', 'pending');
        $this->assertSame(1, $transfer->approvals()->where('action', 'approved')->count());
        $this->patchJson($this->url($transfer), ['source_amount' => '200'])->assertUnprocessable();

        foreach ([1, 2] as $replay) {
            $this->postJson($this->url($transfer).'/sync-status')->assertOk()->assertJsonPath('transfer.status', 'completed');
        }
        $this->assertSame(1, LedgerEntry::query()->where('reference', 'transfer:'.$transfer->id.':debit')->count());
        $this->assertSame('900.00000000', $this->balance->fresh()->ledger_balance);
        $this->assertSame('900.00000000', $this->balance->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->balance->fresh()->reserved_balance);
        $this->patchJson($this->url($transfer), ['status' => 'draft'])->assertUnprocessable();
        $this->deleteJson($this->url($transfer))->assertUnprocessable();
    }

    public function test_dedicated_admin_reject_can_release_a_hold_before_submission(): void
    {
        $transfer = $this->transfer(['status' => 'approval_required']);
        app(LedgerService::class)->reserveTransfer($transfer);
        $this->withToken($this->adminToken)->patchJson($this->url($transfer), ['status' => 'rejected'])->assertUnprocessable();

        $this->postJson($this->url($transfer).'/reject', ['note' => 'Fixture rejection before submission'])
            ->assertOk()->assertJsonPath('transfer.status', 'rejected');

        $this->assertSame(1, $transfer->approvals()->where('action', 'rejected')->count());
        $this->assertSame(1, LedgerEntry::query()->where('reference', 'transfer:'.$transfer->id.':release')->count());
        $this->assertSame('1000.00000000', $this->balance->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->balance->fresh()->reserved_balance);
        $this->deleteJson($this->url($transfer))->assertUnprocessable();
    }

    public static function raceOrders(): array
    {
        return ['financial writer locks first' => [0], 'Admin locks first' => [1]];
    }

    #[DataProvider('raceOrders')]
    public function test_concurrent_admin_update_and_reservation_use_the_same_transfer_lock(int $winner): void
    {
        $transfer = $this->transfer();
        $results = $this->race($winner, function (int $worker) use ($transfer): array {
            if ($worker === 0) {
                $hold = app(LedgerService::class)->reserveTransfer($transfer);

                return ['hold_amount' => $hold->amount];
            }
            $response = $this->withToken($this->adminToken)->patchJson($this->url($transfer), ['source_amount' => '200']);

            return ['http_status' => $response->status(), 'body' => $response->json()];
        });

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
        $this->assertSame($winner === 0 ? 422 : 200, $results[1]['value']['http_status'], json_encode($results));
        $amount = $winner === 0 ? '100.00000000' : '200.00000000';
        $this->assertSame('-'.$amount, $results[0]['value']['hold_amount']);
        $this->assertSame($amount, $transfer->fresh()->source_amount);
        $this->assertSame($amount, $this->balance->fresh()->reserved_balance);
        $this->assertSame(bcsub('1000', $amount, 8), $this->balance->fresh()->available_balance);
        $this->assertSame('1000.00000000', $this->balance->fresh()->ledger_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    #[DataProvider('raceOrders')]
    public function test_concurrent_admin_delete_and_submission_cannot_orphan_a_hold_or_post_after_delete(int $winner): void
    {
        $transfer = $this->transfer();
        $results = $this->race($winner, function (int $worker) use ($transfer): array {
            if ($worker === 1) {
                $response = $this->withToken($this->adminToken)->deleteJson($this->url($transfer));

                return ['http_status' => $response->status(), 'body' => $response->status() === 204 ? null : $response->json()];
            }
            $posts = 0;
            $this->stubProvider($posts);
            try {
                $submitted = app(ProviderTransferManager::class)->submitTransfer($this->provider, $transfer);

                return ['outcome' => $submitted->status, 'posts' => $posts];
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                return ['outcome' => 'deleted_before_reservation', 'posts' => $posts];
            }
        });

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
        if ($winner === 0) {
            $this->assertSame(422, $results[1]['value']['http_status']);
            $this->assertSame(self::DENIED, $results[1]['value']['body']['message']);
            $this->assertSame('pending', $results[0]['value']['outcome']);
            $this->assertSame(1, $results[0]['value']['posts']);
            $this->assertSame('pending', $transfer->fresh()->status);
            $this->assertSame('100.00000000', $this->balance->fresh()->reserved_balance);
            $this->assertSame('900.00000000', $this->balance->fresh()->available_balance);
            $this->assertDatabaseCount('ledger_entries', 1);
        } else {
            $this->assertSame(204, $results[1]['value']['http_status']);
            $this->assertSame('deleted_before_reservation', $results[0]['value']['outcome']);
            $this->assertSame(0, $results[0]['value']['posts']);
            $this->assertNull($transfer->fresh());
            $this->assertSame('0.00000000', $this->balance->fresh()->reserved_balance);
            $this->assertSame('1000.00000000', $this->balance->fresh()->available_balance);
            $this->assertDatabaseCount('ledger_entries', 0);
        }
    }

    public function test_admin_delete_contends_with_the_locked_provider_claim_and_preserves_the_winners_hold(): void
    {
        $transfer = $this->transfer();
        app(LedgerService::class)->reserveTransfer($transfer);
        $results = $this->race(0, function (int $worker) use ($transfer): array {
            if ($worker === 1) {
                $response = $this->withToken($this->adminToken)->deleteJson($this->url($transfer));

                return ['http_status' => $response->status(), 'body' => $response->json()];
            }
            $posts = 0;
            $this->stubProvider($posts);
            $submitted = app(ProviderTransferManager::class)->submitTransfer($this->provider, $transfer);

            return ['outcome' => $submitted->status, 'posts' => $posts];
        }, claimOnly: true);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
        $this->assertSame('pending', $results[0]['value']['outcome']);
        $this->assertSame(1, $results[0]['value']['posts']);
        $this->assertSame(422, $results[1]['value']['http_status']);
        $this->assertSame(self::DENIED, $results[1]['value']['body']['message']);
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertNotEmpty($transfer->fresh()->provider_operation_key);
        $this->assertSame('100.00000000', $this->balance->fresh()->reserved_balance);
        $this->assertSame('900.00000000', $this->balance->fresh()->available_balance);
        $this->assertSame('1000.00000000', $this->balance->fresh()->ledger_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    private function addEvidence(Transfer $transfer, string $case): void
    {
        if (in_array($case, ['hold_without_receipt', 'debit', 'release'], true)) {
            $ledger = app(LedgerService::class);
            $ledger->reserveTransfer($transfer);
            if ($case === 'debit') {
                $ledger->settleTransfer($transfer);
            } elseif ($case === 'release') {
                $ledger->releaseTransferHold($transfer);
            }
            // Historical corruption fixture: journals must protect even after
            // receipt erasure and a status reset. No production repair is done.
            $transfer->refresh()->update(['raw_data' => null, 'status' => 'draft']);

            return;
        }
        match ($case) {
            'semantic_journal', 'canonical_journal' => LedgerEntry::query()->create([
                'provider_id' => $this->provider->id, 'user_id' => $this->owner->id, 'balance_id' => $this->balance->id,
                'reference' => $case === 'canonical_journal' ? 'transfer:'.$transfer->id.':debit' : 'historical-semantic-hold',
                'source_type' => $case === 'semantic_journal' ? 'transfer' : 'contradictory-source',
                'source_id' => $case === 'semantic_journal' ? (string) $transfer->id : 'different-id',
                'entry_type' => 'hold', 'status' => 'pending', 'currency' => 'USD', 'amount' => '-100',
            ]),
            'claim' => $transfer->update(['provider_operation_key' => 'patch259-claim']),
            'external_transfer' => $transfer->update(['external_transfer_id' => 'RT-HISTORICAL']),
            'external_payment' => $transfer->update(['external_payment_id' => 'PAY-HISTORICAL']),
            'submitted_at' => $transfer->update(['submitted_at' => now()]),
            'provider_status_at' => $transfer->update(['provider_status_at' => now()]),
            'provider_status' => $transfer->update(['provider_status' => 'COMPLETED']),
            'completed_at' => $transfer->update(['completed_at' => now()]),
            'failure_evidence' => $transfer->update(['failure_code' => 'provider_submission_unknown']),
            'ambiguous' => $transfer->update(['status' => 'submission_unknown']),
            'terminal' => $transfer->update(['status' => 'completed']),
            'transaction' => Transaction::query()->create([
                'user_id' => $this->owner->id, 'provider_id' => $this->provider->id, 'transfer_id' => $transfer->id,
                'external_transaction_id' => 'patch259-transaction', 'currency' => 'USD', 'amount' => '100', 'status' => 'completed',
            ]),
            'request_log' => ApiRequestLog::query()->create([
                'provider_id' => $this->provider->id, 'user_id' => $this->owner->id, 'related_transfer_id' => $transfer->id,
                'request_method' => 'POST', 'request_url' => 'https://fixture.invalid/remittance',
            ]),
            'compliance_event' => NiumComplianceEvent::query()->create([
                'provider_id' => $this->provider->id, 'transfer_id' => $transfer->id,
                'event_id' => 'patch259-compliance', 'payload' => [],
            ]),
            'quote' => $transfer->update(['fx_quote_id' => FxQuote::query()->create([
                'user_id' => $this->owner->id, 'provider_id' => $this->provider->id, 'quote_ref' => 'fixture-bound-quote',
                'source_currency' => 'USD', 'target_currency' => 'EUR', 'source_amount' => '100',
                'target_amount' => '90', 'net_rate' => '0.9', 'expires_at' => now()->addMinutes(10),
            ])->id]),
            'approval' => $transfer->approvals()->create(['approver_user_id' => $this->admin->id, 'action' => 'approved']),
            'raw_receipt' => $transfer->update(['raw_data' => ['wallet' => ['hold_reference' => 'missing-journal']]]),
            'raw_provider_result' => $transfer->update(['raw_data' => ['provider_request_id' => 'historical-request']]),
        };
    }

    private function transfer(array $changes = []): Transfer
    {
        return Transfer::query()->create(array_replace([
            'transfer_no' => 'PATCH259-'.Str::uuid(), 'user_id' => $this->owner->id,
            'provider_id' => $this->provider->id, 'beneficiary_id' => $this->beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => '100', 'fee_amount' => '0', 'fee_currency' => 'USD',
            'purpose_code' => 'IR01811', 'reference_text' => 'PATCH259', 'status' => 'draft',
        ], $changes));
    }

    private function foreignProvider(): IntegrationProvider
    {
        return IntegrationProvider::query()->create(['code' => 'foreign-fixture', 'name' => 'Foreign fixture', 'status' => 'active']);
    }

    private function token(User $user): string
    {
        $plain = Str::random(80);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'patch259',
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDay()]);

        return $plain;
    }

    private function url(Transfer $transfer): string
    {
        return '/api/admin/transfers/'.$transfer->id;
    }

    private function snapshot(Transfer $transfer): array
    {
        return [
            'transfer' => $transfer->fresh()->getAttributes(), 'balance' => $this->balance->fresh()->getAttributes(),
            'journals' => LedgerEntry::query()->orderBy('id')->get()->toArray(),
            'approvals' => $transfer->approvals()->orderBy('id')->get()->toArray(),
            'transactions' => Transaction::query()->orderBy('id')->get()->toArray(),
            'logs' => ApiRequestLog::query()->orderBy('id')->get()->toArray(),
            'compliance' => NiumComplianceEvent::query()->orderBy('id')->get()->toArray(),
        ];
    }

    private function response(array $body): \Illuminate\Http\Client\Response
    {
        return new \Illuminate\Http\Client\Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function stubProvider(?int &$posts = null): void
    {
        // Status reconciliation calls the real customerId()/walletId() methods;
        // initialize their readonly state-service dependency in this partial.
        $nium = \Mockery::mock(NiumService::class, [app(NiumProviderAccountStateService::class)])->makePartial();
        $this->app->instance(NiumService::class, $nium);
        $nium->shouldReceive('get')->andReturnUsing(function (...$arguments) {
            $this->assertSame(0, DB::transactionLevel());
            $path = $arguments['path'] ?? $arguments[0] ?? '';

            return str_contains($path, 'purposeCodes')
                ? $this->response([['description' => 'General Goods', 'purposeCode' => 'IR01811']])
                : $this->response([['status' => 'COMPLETED', 'dateTime' => now()->addSecond()->toISOString()]]);
        });
        $nium->shouldReceive('post')->atMost()->once()->andReturnUsing(function (...$arguments) use (&$posts) {
            $this->assertSame(0, DB::transactionLevel());
            $payload = $arguments['payload'] ?? $arguments[1] ?? [];
            $this->assertSame('SWIFT', $payload['payout']['payoutMethod']);
            $this->assertSame('USD', $payload['payout']['sourceCurrency']);
            $this->assertSame('USD', $payload['payout']['destinationCurrency']);
            $posts = ($posts ?? 0) + 1;

            return $this->response(['systemReferenceNumber' => 'RT-PATCH259', 'paymentId' => 'PAY-PATCH259']);
        });
    }

    /** Both workers overlap: wait for a real PostgreSQL blocker at reservation/CRUD or claim. */
    private function race(int $winner, callable $operation, bool $claimOnly = false): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'PATCH259 PostgreSQL races require pcntl.');
        $directory = tempnam(sys_get_temp_dir(), 'patch259-');
        unlink($directory);
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach ([0, 1] as $worker) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::reconnect();
                        DB::statement("SET lock_timeout = '8s'");
                        DB::statement("SET statement_timeout = '12s'");
                        $backend = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                        file_put_contents($directory.'/backend-'.$worker, (string) $backend);
                        $this->await(fn () => is_file($directory.'/start'));
                        if ($worker === $winner) {
                            $paused = false;
                            DB::listen(function (QueryExecuted $query) use (&$paused, $directory, $worker, $backend, $claimOnly): void {
                                $boundary = $claimOnly
                                    ? str_starts_with($query->sql, 'update "transfers"') && str_contains($query->sql, '"provider_operation_key"')
                                    : str_contains($query->sql, 'from "transfers"') && str_contains($query->sql, 'for update');
                                if ($paused || ! $boundary) {
                                    return;
                                }
                                $paused = true;
                                file_put_contents($directory.'/winner-locked', 'ready');
                                $other = (int) file_get_contents($directory.'/backend-'.(1 - $worker));
                                $this->await(function () use ($other, $backend): bool {
                                    $row = DB::selectOne('SELECT CAST(? AS integer) = ANY(pg_blocking_pids(CAST(? AS integer))) AS blocked', [$backend, $other]);

                                    return in_array($row->blocked, [true, 1, '1', 't'], true);
                                });
                                file_put_contents($directory.'/observed-contention', 'yes');
                            });
                        } else {
                            $this->await(fn () => is_file($directory.'/winner-locked'));
                        }
                        $result = ['status' => 'ok', 'value' => $operation($worker)];
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'value' => $exception::class.': '.$exception->getMessage()];
                    }
                    try {
                        Http::assertNothingSent();
                        \Mockery::close();
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'value' => $exception::class.': '.$exception->getMessage()];
                    }
                    file_put_contents($directory.'/result-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                }
                $this->assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            $this->await(fn () => is_file($directory.'/backend-0') && is_file($directory.'/backend-1')
                && (int) file_get_contents($directory.'/backend-0') > 0 && (int) file_get_contents($directory.'/backend-1') > 0);
            file_put_contents($directory.'/start', 'ready');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            DB::reconnect();
            $results = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/result-'.$worker), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
            $this->assertFileExists($directory.'/observed-contention', json_encode($results));

            return $results;
        } finally {
            file_put_contents($directory.'/start', 'ready');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 6;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('PATCH259 PostgreSQL contention barrier timed out.');
            }
            usleep(1000);
        }
    }
}
