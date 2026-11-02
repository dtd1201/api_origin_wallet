<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\FxQuote;
use App\Models\LedgerEntry;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumRejectedPayoutQuoteRecovery;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesRejectedSgdQuoteIncident;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumRejectedPayoutQuoteRecoveryTest extends TestCase
{
    use CreatesRejectedSgdQuoteIncident, CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    public function test_validation_does_not_write_and_apply_is_idempotent_with_original_evidence_retained(): void
    {
        [$transfer, $operator, $clearance, $log, $evidence] = $this->rejectedIncident();
        $before = $transfer->raw_data;
        $captured = $evidence->fresh()->getRawOriginal();
        $recovery = app(NiumRejectedPayoutQuoteRecovery::class);
        $recovery->recover($operator, $clearance, $this->rejectedProviderArtifact);
        $this->assertSame($before, $transfer->fresh()->raw_data);
        $result = $recovery->recover($operator, $clearance, $this->rejectedProviderArtifact, true);
        $this->assertSame('draft', $result->status);
        $this->assertSame('draft', $result->raw_data['sgd_local_workflow']['state']);
        $receipt = $result->raw_data['sgd_local_workflow']['rejected_quote_recoveries'][NiumRejectedPayoutQuoteRecovery::REQUEST];
        $this->assertSame($before['sgd_local_workflow'], $receipt['original_attempt']);
        $this->assertSame(4763, $receipt['api_request_log_id']);
        $this->assertSame($operator->id, $receipt['operator_id']);
        $this->assertSame($result->raw_data, $recovery->recover($operator, $clearance, $this->rejectedProviderArtifact, true)->raw_data);
        $this->assertSame($captured, $evidence->fresh()->getRawOriginal());
        $this->assertSame(400, $log->fresh()->response_status);
        $this->assertNull($result->fx_quote_id);
        $this->assertNull($result->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
        Http::assertNothingSent();
    }

    public static function refusals(): array
    {
        return array_map(fn ($v) => [$v], ['uuid', 'owner', 'intent', 'audit_uuid', 'duplicate_audit', 'missing_capture',
            'hash', 'timeout', 'success', 'wrong_error', 'contradictory_response', 'clearance', 'clearance_hash',
            'no_clearance_reference', 'operator', 'open', 'active_lease', 'quote', 'reservation', 'ledger',
            'operation', 'consent', 'timing', 'capture_uuid', 'capture_method', 'wrong_request', 'wrong_url', 'artifact',
            'historical_remittance', 'historical_ledger']);
    }

    #[DataProvider('refusals')]
    public function test_missing_uncertain_or_conflicting_evidence_refuses_without_provider_io(string $case): void
    {
        [$transfer, $operator, $clearance, $log, $evidence] = $this->rejectedIncident();
        $raw = $transfer->raw_data;
        $lease = null;
        switch ($case) {
            case 'historical_remittance':
                $copy = $log->replicate();
                $copy->forceFill(['operation' => 'transfer_money', 'related_transfer_id' => 5,
                    'request_headers' => ['x-request-id' => Str::uuid()->toString()], 'created_at' => now()->subDay()])->save();
                break;
            case 'historical_ledger':
                LedgerEntry::query()->forceCreate(['balance_id' => Balance::query()->sole()->id, 'user_id' => 12,
                    'provider_id' => 10, 'reference' => 'historical-transfer-evidence', 'source_type' => 'transfer', 'source_id' => '5',
                    'entry_type' => 'hold', 'currency' => 'USD', 'amount' => -5, 'balance_after' => 80,
                    'posted_at' => now()->subDay(), 'created_at' => now()->subDay()]);
                break;
            case 'uuid': $raw['sgd_local_workflow']['lock_request_id'] = Str::uuid()->toString();
                break;
            case 'owner': $transfer->user_id = $operator->id;
                break;
            case 'intent': $transfer->source_amount = '5.01';
                break;
            case 'audit_uuid': $log->update(['request_headers' => ['x-request-id' => Str::uuid()->toString()]]);
                break;
            case 'duplicate_audit': $copy = $log->replicate();
                $copy->save();
                break;
            case 'missing_capture': $evidence->delete();
                break;
            case 'hash': $evidence->update(['response_body_sha256' => str_repeat('b', 64)]);
                break;
            case 'timeout': $log->update(['response_status' => null, 'transport_outcome' => 'connection_exception']);
                break;
            case 'success': $log->update(['response_status' => 200, 'is_success' => true]);
                break;
            case 'wrong_error':
            case 'contradictory_response':
                $body = $case === 'wrong_error' ? ['errors' => [['code' => 'other']]]
                    : ['errors' => [NiumRejectedPayoutQuoteRecovery::ERROR], 'id' => 'created-quote'];
                $body = json_encode($body, JSON_THROW_ON_ERROR);
                $evidence->update(['raw_response_body' => $body, 'response_body_sha256' => hash('sha256', $body)]);
                $clearance['response_body_sha256'] = hash('sha256', $body);
                break;
            case 'artifact': $clearance['provider_review']['artifact_sha256'] = str_repeat('a', 64);
                break;
            case 'clearance': $clearance['provider_review']['no_conversion'] = false;
                break;
            case 'clearance_hash': $clearance['request_body_sha256'] = str_repeat('c', 64);
                break;
            case 'no_clearance_reference': unset($clearance['provider_review']['reference']);
                break;
            case 'operator': $operator = $transfer->user;
                $clearance['reviewed_by'] = $operator->id;
                break;
            case 'open': file_put_contents($this->sgdFenceDirectory.'/state', "OPEN v1\n");
                break;
            case 'active_lease': $lease = fopen($this->sgdFenceDirectory.'/drain.lock', 'rb');
                flock($lease, LOCK_SH);
                break;
            case 'quote': FxQuote::query()->create(['provider_id' => 10, 'user_id' => 12, 'source_currency' => 'USD',
                'target_currency' => 'SGD', 'source_amount' => 5, 'target_amount' => 6, 'net_rate' => 1.2,
                'fee_amount' => 0, 'quote_ref' => 'contradictory', 'expires_at' => now()->addMinutes(5)]);
                break;
            case 'reservation': Balance::query()->sole()->update(['reserved_balance' => 5]);
                break;
            case 'ledger': LedgerEntry::query()->create(['balance_id' => Balance::query()->sole()->id, 'user_id' => 12,
                'provider_id' => 10, 'reference' => 'transfer:5:hold', 'entry_type' => 'hold', 'currency' => 'USD',
                'amount' => -5, 'balance_after' => 80, 'posted_at' => now()]);
                break;
            case 'operation': $transfer->provider_operation_key = 'nium-'.Str::uuid();
                break;
            case 'consent': $raw['sgd_local_workflow']['customer_authorization'] = ['accepted_by' => 12];
                break;
            case 'timing': $log->update(['request_started_at' => now()->subHours(2)]);
                break;
            case 'capture_uuid': $evidence->update(['request_headers' => ['x-request-id' => ['wrong']]]);
                break;
            case 'capture_method': $evidence->update(['request_method' => 'GET']);
                break;
            case 'wrong_request':
                $body = json_encode(['sourceAmount' => 99], JSON_THROW_ON_ERROR);
                $evidence->update(['raw_request_body' => $body, 'request_body_sha256' => hash('sha256', $body)]);
                $clearance['request_body_sha256'] = hash('sha256', $body);
                break;
            case 'wrong_url': $log->update(['request_url' => 'https://other.invalid/quotes']);
                break;
        }
        $transfer->raw_data = $raw;
        $transfer->save();
        $before = $transfer->fresh()->raw_data;
        try {
            app(NiumRejectedPayoutQuoteRecovery::class)->recover($operator, $clearance, $this->rejectedProviderArtifact, true);
            $this->fail('Unsafe recovery must refuse: '.$case);
        } catch (RuntimeException|ValidationException) {
            $this->assertSame($before, $transfer->fresh()->raw_data);
        } finally {
            if ($lease !== null) {
                fclose($lease);
            }
        }
        Http::assertNothingSent();
    }

    public function test_new_quote_review_customer_consent_and_independent_approve_keep_one_dispatch(): void
    {
        [$transfer, $operator, $clearance] = $this->rejectedIncident();
        $recovered = app(NiumRejectedPayoutQuoteRecovery::class)->recover($operator, $clearance, $this->rejectedProviderArtifact, true);
        $this->fakeSgdProvider();
        file_put_contents($this->sgdFenceDirectory.'/state', "OPEN v1\n");
        $input = ['provider_id' => 10, 'beneficiary_id' => 9, 'source_amount' => '5', 'purpose_code' => 'IR008',
            'client_reference' => NiumRejectedPayoutQuoteRecovery::REFERENCE, 'reference_text' => 'SYNTHETIC-INCIDENT', 'transfer_id' => 5];
        $this->withToken($this->sgdToken($recovered->user));
        $review = $this->postJson('/api/user/users/12/transfers/review', $input)->assertOk()->assertJsonPath('id', 5);
        $locked = $transfer->fresh();
        $this->assertSame('locked', $locked->raw_data['sgd_local_workflow']['state']);
        $this->assertArrayNotHasKey('customer_authorization', $locked->raw_data['sgd_local_workflow']);
        $this->postJson('/api/user/users/12/transfers', [...$input, 'transfer_type' => 'payout',
            'source_currency' => 'USD', 'target_currency' => 'SGD', 'review_token' => $review->json('review_token'),
            'accept_payment_terms' => true, 'purpose_confirmed' => true])->assertCreated();
        $requested = $transfer->fresh();
        $this->assertSame('approval_required', $requested->status);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/remittance'));
        try {
            app(TransferApprovalService::class)->approve($requested, $requested->user, manager: app(ProviderTransferManager::class));
            $this->fail('Maker may not approve their payment.');
        } catch (RuntimeException) {
            $this->assertNull($requested->fresh()->provider_operation_key);
        }
        $this->withToken($this->sgdToken($operator))->postJson('/api/admin/transfers/5/approve')->assertOk();
        $pending = $transfer->fresh();
        $this->assertSame('pending', $pending->status);
        $operation = $pending->provider_operation_key;
        try {
            app(TransferApprovalService::class)->approve($pending, $operator, manager: app(ProviderTransferManager::class));
        } catch (RuntimeException) {
            $this->assertSame($operation, $pending->fresh()->provider_operation_key);
        }
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/remittance')));
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        // Replaying recovery after submission returns the existing row without undoing state.
        file_put_contents($this->sgdFenceDirectory.'/state', "CLOSED v1\n");
        $replay = app(NiumRejectedPayoutQuoteRecovery::class)->recover($operator, $clearance, $this->rejectedProviderArtifact, true);
        $this->assertSame('pending', $replay->status);
        $this->assertSame($operation, $replay->provider_operation_key);
    }

    public function test_retired_request_uuid_cannot_be_reused_for_a_new_quote(): void
    {
        [$transfer, $operator, $clearance] = $this->rejectedIncident();
        $result = app(NiumRejectedPayoutQuoteRecovery::class)->recover($operator, $clearance, $this->rejectedProviderArtifact, true);
        file_put_contents($this->sgdFenceDirectory.'/state', "OPEN v1\n");
        $this->fakeSgdProvider();
        $this->expectException(ValidationException::class);
        app(NiumSgdLocalPayoutWorkflow::class)->requestLock($result, NiumRejectedPayoutQuoteRecovery::REQUEST);
    }

    public function test_command_defaults_to_validation_and_never_exposes_capture_or_provider_ids(): void
    {
        [$transfer, $operator, $clearance] = $this->rejectedIncident();
        $file = tempnam(sys_get_temp_dir(), 'sgd-rejection-clearance-');
        chmod($file, 0600);
        file_put_contents($file, json_encode($clearance, JSON_THROW_ON_ERROR));
        $artifact = tempnam(sys_get_temp_dir(), 'sgd-provider-artifact-');
        chmod($artifact, 0600);
        file_put_contents($artifact, $this->rejectedProviderArtifact);
        try {
            $this->artisan('nium:recover-rejected-sgd-quote', ['operator' => $operator->id, '--clearance' => $file, '--provider-evidence' => $artifact])
                ->expectsOutput('PASS evidence validation only; transfer unchanged.')->assertExitCode(0);
            $this->assertSame('lock_unknown', $transfer->fresh()->raw_data['sgd_local_workflow']['state']);
            $this->artisan('nium:recover-rejected-sgd-quote', ['operator' => $operator->id, '--clearance' => $file, '--provider-evidence' => $artifact, '--apply' => true])
                ->expectsOutput('PASS local recovery recorded; no provider operation performed.')->assertExitCode(0);
            chmod($file, 0644);
            clearstatcache(true, $file);
            $this->artisan('nium:recover-rejected-sgd-quote', ['operator' => $operator->id, '--clearance' => $file, '--provider-evidence' => $artifact, '--apply' => true])
                ->expectsOutput('FAIL recovery refused; keep admission closed and review incident evidence.')->assertExitCode(1);
            Http::assertNothingSent();
        } finally {
            unlink($file);
            unlink($artifact);
        }
    }

    public static function newRequestFailures(): array
    {
        return [['400'], ['timeout']];
    }

    #[DataProvider('newRequestFailures')]
    public function test_new_rejection_or_timeout_does_not_automatically_grant_another_attempt(string $outcome): void
    {
        [$transfer, $operator, $clearance] = $this->rejectedIncident();
        $recovered = app(NiumRejectedPayoutQuoteRecovery::class)->recover($operator, $clearance, $this->rejectedProviderArtifact, true);
        file_put_contents($this->sgdFenceDirectory.'/state', "OPEN v1\n");
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['*purposeCodes*' => Http::response([['purposeCode' => 'IR008', 'description' => 'Synthetic only']]),
            '*supportedCorridors*' => Http::response(['content' => [['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD',
                'payoutMethod' => 'LOCAL', 'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT']]]),
            '*/quotes' => function () use ($outcome) {
                if ($outcome === 'timeout') {
                    throw new ConnectionException('Synthetic only');
                }

                return Http::response(['errors' => [NiumRejectedPayoutQuoteRecovery::ERROR]], 400);
            }]);
        $uuid = Str::uuid()->toString();
        try {
            app(NiumSgdLocalPayoutWorkflow::class)->requestLock($recovered, $uuid);
            $this->fail('Unsuccessful quote may not become locked.');
        } catch (RuntimeException|ConnectionException) {
            $current = $transfer->fresh();
            $this->assertSame('lock_unknown', $current->raw_data['sgd_local_workflow']['state']);
            $this->assertNull($current->fx_quote_id);
        }
        try {
            app(NiumSgdLocalPayoutWorkflow::class)->requestLock($transfer->fresh(), Str::uuid()->toString());
            $this->fail('Uncertain quote must not retry.');
        } catch (ValidationException) {
            $this->assertSame(2, ApiRequestLog::query()->where('operation', 'create_payout_fx_lock')->count());
            $this->assertSame($outcome === 'timeout' ? 0 : 1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/quotes'))->count());
        }
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/remittance'));
    }
}
