<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumPayoutPilotPolicy;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumReviewBalanceFreshnessTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private int $balanceCalls = 0;

    private function fixture(): array
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        // Synthetic Transfer 3; no Production connection or records are used.
        $transfer->forceFill(['id' => 3])->save();
        $this->withToken($this->sgdToken($transfer->user));

        return [$provider, $transfer->fresh(), $checker];
    }

    private function payload(Transfer $transfer): array
    {
        return ['transfer_id' => $transfer->id, 'provider_id' => 10, 'beneficiary_id' => 9,
            'source_amount' => '5', 'purpose_code' => 'SYNTHETIC_PURPOSE',
            'client_reference' => $transfer->client_reference, 'preparation_id' => Str::uuid()->toString()];
    }

    private function review(Transfer $transfer)
    {
        return $this->postJson('/api/user/users/12/sgd-local-payouts/review', $this->payload($transfer));
    }

    private function providerBalance(array $changes = []): array
    {
        return array_replace(['walletHashId' => 'synthetic-wallet', 'customerHashId' => 'synthetic-customer',
            'currency' => 'USD', 'availableBalance' => '85', 'updatedAt' => now()->toISOString()], $changes);
    }

    private function fakeBalance(array|\Closure $response, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($response, $status) {
            $this->assertSame('GET', $request->method(), 'Review can never dispatch a payout.');
            if (str_ends_with($request->url(), '/wallet/synthetic-wallet')) {
                $this->assertSame(0, DB::transactionLevel(), 'Never hold a DB transaction across NIUM I/O.');
                $this->assertFalse($options['allow_redirects']);
                $this->balanceCalls++;

                return $response instanceof \Closure ? $response() : Http::response($response, $status);
            }
            if (str_contains($request->url(), 'purposeCodes')) {
                return Http::response([['purposeCode' => 'SYNTHETIC_PURPOSE', 'description' => 'Synthetic only']]);
            }
            if (str_contains($request->url(), 'supportedCorridors')) {
                return Http::response(['content' => [['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD',
                    'payoutMethod' => 'LOCAL', 'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT']]]);
            }
            if (str_contains($request->url(), 'lockExchangeRate')) {
                return Http::response(['audit_id' => 123456, 'fx_hold_id' => 'synthetic-new-hold',
                    'fx_rate' => '1.275939120', 'source_currency' => 'USD', 'destination_currency' => 'SGD',
                    'status' => 'ACTIVE', 'hold_expiry_at' => now()->addMinutes(10)->toISOString()]);
            }
            throw new \RuntimeException('Unexpected provider request');
        });
    }

    public function test_stale_transfer_three_balance_refreshes_once_then_customer_and_admin_submit_normally(): void
    {
        [, $transfer, $checker] = $this->fixture();
        $before = $transfer->getAttributes();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $this->fakeBalance(['balances' => [$this->providerBalance()]]);
        $review = $this->review($transfer)->assertOk()->assertJsonPath('id', 3)
            ->assertJsonPath('payout_workflow.approval_request_allowed', true);
        $this->review($transfer)->assertOk();
        $this->assertSame(1, $this->balanceCalls);
        $this->assertSame('85.00000000', Balance::query()->sole()->available_balance);
        $this->assertTrue(Balance::query()->sole()->as_of->greaterThan(now()->subMinutes(5)));
        $this->assertSame($before, $transfer->fresh()->getAttributes());
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
        $receipt = ['submission_token' => $review->json('payout_workflow.submission_token'),
            'accept_payment_terms' => true, 'purpose_confirmed' => true];
        $this->postJson('/api/user/users/12/transfers/3/sgd-local-submit', $receipt)->assertOk()->assertJsonPath('status', 'approval_required');
        $this->fakeSgdProvider();
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/3/approve')->assertOk();
        $this->postJson('/api/admin/transfers/3/approve')->assertOk();
        $this->assertSame(1, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_refresh_preserves_the_exact_uncapped_pilot_grant_without_customer_acceptance_or_dispatch(): void
    {
        [, $transfer, $checker] = $this->fixture();
        $grant = app(NiumPayoutPilotPolicy::class)->authorizeOneTime($transfer, $checker, 'SYNTHETIC-ONLY', true);
        config()->set('services.nium.payout_pilot', ['enabled' => true, 'authorization_id' => $grant->id]);
        $before = $grant->fresh()->getAttributes();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $this->fakeBalance(['balances' => [$this->providerBalance()]]);
        $this->review($transfer)->assertOk()
            ->assertJsonPath('payout_workflow.approval_request_allowed', true)
            ->assertJsonPath('payout_workflow.customer_instruction.financial_terms.policy', 'one_time_uncapped')
            ->assertJsonPath('payout_workflow.customer_instruction.fx_quote_id', $transfer->fx_quote_id)
            ->assertJsonPath('payout_workflow.customer_authorization', null);
        $this->assertSame($before, $grant->fresh()->getAttributes());
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_fresh_or_future_local_snapshots_are_not_refreshed_and_submit_does_not_sync(): void
    {
        [, $transfer] = $this->fixture();
        $receipt = $this->reviewToken($transfer);
        $this->fakeBalance(['balances' => [$this->providerBalance()]]);
        $this->review($transfer)->assertOk();
        Balance::query()->update(['as_of' => now()->addMinute()]);
        $this->review($transfer)->assertUnprocessable();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $this->postJson('/api/user/users/12/transfers/3/sgd-local-submit', $receipt)->assertUnprocessable();
        $this->assertSame(0, $this->balanceCalls);
    }

    public static function invalidResponses(): array
    {
        return array_map(fn ($case) => [$case], ['unavailable', 'empty', 'missing_amount', 'non_numeric',
            'wrong_wallet', 'wrong_customer', 'wrong_client', 'wrong_currency', 'conflicting_currency', 'duplicate_currency', 'stale', 'future', 'timeout', 'insufficient']);
    }

    #[DataProvider('invalidResponses')]
    public function test_unverified_or_unusable_provider_balances_never_admit_review(string $case): void
    {
        [, $transfer] = $this->fixture();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $before = Balance::query()->sole()->getAttributes();
        $row = $this->providerBalance();
        switch ($case) {
            case 'missing_amount': unset($row['availableBalance']);
                break;
            case 'non_numeric': $row['availableBalance'] = 'unavailable';
                break;
            case 'wrong_wallet': $row['walletHashId'] = 'unrelated-wallet';
                break;
            case 'wrong_customer': $row['customerHashId'] = 'other-customer';
                break;
            case 'wrong_client': $row['clientHashId'] = 'other-client';
                break;
            case 'wrong_currency': $row['currency'] = 'SGD';
                break;
            case 'conflicting_currency': $row['currencyCode'] = 'SGD';
                break;
            case 'stale': $row['updatedAt'] = now()->subMinutes(5)->toISOString();
                break;
            case 'future': $row['updatedAt'] = now()->addMinute()->toISOString();
                break;
            case 'insufficient': $row['availableBalance'] = '4.99999999';
                break;
        }
        $rows = $case === 'empty' ? [] : ($case === 'duplicate_currency' ? [$row, $row] : [$row]);
        $this->fakeBalance($case === 'timeout' ? function () {
            throw new ConnectionException('Synthetic timeout');
        }
            : ['balances' => $rows], $case === 'unavailable' ? 503 : 200);
        $this->review($transfer)->assertUnprocessable()->assertJsonPath('transfer.status', 'draft');
        $this->assertSame(1, $this->balanceCalls);
        if ($case !== 'insufficient') {
            $this->assertSame($before, Balance::query()->sole()->getAttributes());
        }
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::where('operation', 'transfer_money')->count());
    }

    public function test_wallet_bound_endpoint_can_supply_identity_and_retrieval_time_when_omitted_from_response(): void
    {
        [, $transfer] = $this->fixture();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $this->fakeBalance(['balances' => [['currency' => 'USD', 'availableBalance' => '85']]]);
        $this->review($transfer)->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/client/synthetic-client/customer/synthetic-customer/wallet/synthetic-wallet'));
        $this->assertTrue(Balance::query()->sole()->as_of->greaterThan(now()->subMinute()));
    }

    public function test_stale_wrapper_timestamp_cannot_be_replaced_with_the_retrieval_time(): void
    {
        [, $transfer] = $this->fixture();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $before = Balance::query()->sole()->getAttributes();
        $this->fakeBalance(['data' => ['wallet' => ['walletHashId' => 'synthetic-wallet',
            'updatedAt' => now()->subHour()->toISOString(), 'balances' => [['currency' => 'USD', 'availableBalance' => '85']]]]]);
        $this->review($transfer)->assertUnprocessable();
        $this->assertSame($before, Balance::query()->sole()->getAttributes());
    }

    public function test_wrong_local_wallet_or_owner_is_never_substituted_or_reassigned(): void
    {
        [, $transfer] = $this->fixture();
        $balance = Balance::query()->sole();
        $balance->update(['external_account_id' => 'unrelated-wallet', 'as_of' => now()->subMinutes(6)]);
        $this->fakeBalance(['balances' => [$this->providerBalance()]]);
        $this->review($transfer)->assertUnprocessable();
        $other = User::factory()->create();
        $balance->update(['external_account_id' => 'synthetic-wallet', 'user_id' => $other->id]);
        $this->review($transfer)->assertUnprocessable();
        $this->assertSame(0, $this->balanceCalls);
        $this->assertSame($other->id, $balance->fresh()->user_id);
    }

    public static function lockStores(): array
    {
        return [['array'], ['database']];
    }

    #[DataProvider('lockStores')]
    public function test_concurrent_reviews_share_one_refresh_and_reuse_its_result(string $store): void
    {
        config()->set('cache.default', $store);
        [, $transfer] = $this->fixture();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $concurrent = null;
        $this->fakeBalance(function () use ($transfer, &$concurrent) {
            // Interleave another review while the first authenticated HTTP request is in flight.
            try {
                app(NiumSgdLocalPayoutWorkflow::class)->prepareReview($transfer->user, $this->payload($transfer));
                $this->fail('Concurrent preparation must wait for the owning balance refresh.');
            } catch (ValidationException $failure) {
                $concurrent = $failure->errors();
            }

            return Http::response(['balances' => [$this->providerBalance()]]);
        });
        $this->review($transfer)->assertOk();
        $this->assertStringContainsString('being refreshed', $concurrent['payout'][0]);
        $this->review($transfer)->assertOk();
        $this->assertSame(1, $this->balanceCalls);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_timeout_releases_the_refresh_lock_for_a_safe_read_only_retry(): void
    {
        [, $transfer] = $this->fixture();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $this->fakeBalance(function () {
            throw new ConnectionException('Synthetic timeout');
        });
        $this->review($transfer)->assertUnprocessable();
        $this->fakeBalance(['balances' => [$this->providerBalance()]]);
        $this->review($transfer)->assertOk();
        $this->assertSame(2, $this->balanceCalls);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_refresh_preserves_origin_reservations_and_blocks_unreconciled_provider_discrepancies(): void
    {
        [, $transfer] = $this->fixture();
        $balance = Balance::query()->sole();
        $balance->update(['reserved_balance' => '10', 'ledger_balance' => '95', 'as_of' => now()->subMinutes(6),
            'raw_data' => ['_origin_ledger' => ['last_hold_reference' => 'other-transfer:hold']]]);
        LedgerEntry::query()->create(['balance_id' => $balance->id, 'user_id' => 12, 'provider_id' => 10,
            'reference' => 'other-transfer:hold', 'entry_type' => 'hold', 'status' => 'posted',
            'currency' => 'USD', 'amount' => '-10', 'balance_after' => '85', 'posted_at' => now()]);
        $this->fakeBalance(['balances' => [$this->providerBalance()]]);
        $this->review($transfer)->assertOk();
        $balance->refresh();
        $this->assertSame('85.00000000', $balance->available_balance);
        $this->assertSame('95.00000000', $balance->ledger_balance);
        $this->assertSame('10.00000000', $balance->reserved_balance);
        $this->assertSame('other-transfer:hold', $balance->raw_data['_origin_ledger']['last_hold_reference']);
        $balance->update(['as_of' => now()->subMinutes(6)]);
        $before = $balance->fresh()->getAttributes();
        $this->fakeBalance(['balances' => [$this->providerBalance(['availableBalance' => '95'])]]);
        $this->review($transfer)->assertUnprocessable();
        $this->assertSame($before, $balance->fresh()->getAttributes());
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_identity_change_during_the_provider_request_cannot_refresh_a_different_wallet(): void
    {
        [, $transfer] = $this->fixture();
        Balance::query()->update(['as_of' => now()->subMinutes(6)]);
        $before = Balance::query()->sole()->getAttributes();
        $this->fakeBalance(function () use ($transfer) {
            $transfer->user->providerAccounts()->update(['external_account_id' => 'another-wallet']);

            return Http::response(['balances' => [$this->providerBalance()]]);
        });
        $this->review($transfer)->assertUnprocessable();
        $this->assertSame($before, Balance::query()->sole()->getAttributes());
    }

    public function test_new_draft_refreshes_stale_zero_before_principal_validation(): void
    {
        [, $transfer] = $this->fixture();
        Balance::query()->update(['available_balance' => '0', 'ledger_balance' => '0', 'as_of' => now()->subMinutes(6)]);
        $this->fakeBalance(['balances' => [$this->providerBalance()]]);
        $input = $this->payload($transfer);
        unset($input['transfer_id']);
        $input['client_reference'] = 'NEW-SYNTHETIC-PAYMENT';
        $this->postJson('/api/user/users/12/sgd-local-payouts/review', $input)->assertOk()
            ->assertJsonPath('payout_workflow.approval_request_allowed', true);
        $this->assertSame(1, $this->balanceCalls);
    }
}
