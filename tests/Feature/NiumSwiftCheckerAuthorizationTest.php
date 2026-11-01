<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Beneficiary;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumSwiftPayoutAuthorization;
use App\Services\Nium\NiumService;
use App\Services\Wallet\TransferApprovalService;
use App\Services\Wallet\LedgerService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumSwiftCheckerAuthorizationTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function hkTransfer(): array
    {
        [$provider, $fixture, $checker] = $this->sgdExecutionFixture();
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        $beneficiary = Beneficiary::query()->create(['user_id' => $fixture->user_id, 'provider_id' => $provider->id,
            'external_beneficiary_id' => 'synthetic-hk-beneficiary', 'beneficiary_type' => 'business',
            'full_name' => 'Synthetic HK', 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT',
            'status' => 'active', 'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']]]);
        $this->withToken($this->sgdToken($fixture->user));
        $created = $this->postJson("/api/user/users/{$fixture->user_id}/transfers", [
            'provider_id' => $provider->id, 'beneficiary_id' => $beneficiary->id,
            'source_amount' => '5', 'purpose_code' => 'SYNTHETIC_PURPOSE', 'reference_text' => 'SWIFT-SECURITY',
            'client_reference' => 'SWIFT-SECURITY', 'transfer_type' => 'payout',
            'source_currency' => 'USD', 'target_currency' => 'USD',
            // A customer cannot choose an independent maker to bypass the guard.
            'raw_data' => ['nium_approval' => ['version' => 1, 'maker_user_id' => $checker->id]],
        ])->assertCreated();
        $transfer = Transfer::query()->findOrFail($created->json('id'));
        $this->assertSame(NiumSwiftPayoutAuthorization::origin($fixture->user, $fixture->user, $provider),
            $transfer->raw_data['nium_approval']);

        return [$provider, $transfer, $checker];
    }

    private function assertUnsubmitted(Transfer $transfer, int $calls, int $approvals = 0): void
    {
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', $approvals);
        $this->assertSame($calls, count(Http::recorded()));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_direct_service_self_approval_is_rejected_even_for_an_admin_maker(): void
    {
        [, $transfer] = $this->hkTransfer();
        $maker = $transfer->user;
        $maker->roles()->create(['role_code' => 'admin']);
        $calls = count(Http::recorded());
        try {
            app(TransferApprovalService::class)->approve($transfer, $maker, manager: app(ProviderTransferManager::class));
            $this->fail('A direct service call must enforce checker independence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('maker cannot approve', $exception->getMessage());
        }
        $this->assertUnsubmitted($transfer, $calls);
    }

    public static function invalidOrigins(): array
    {
        return ['legacy absent' => ['absent'], 'null maker' => ['null'], 'string maker' => ['string'],
            'unknown maker' => ['unknown'], 'conflicting owner' => ['owner'], 'conflicting provider' => ['provider']];
    }

    #[DataProvider('invalidOrigins')]
    public function test_ambiguous_legacy_attribution_blocks_api_and_alternate_dispatch(string $change): void
    {
        [$provider, $transfer, $checker] = $this->hkTransfer();
        $raw = $transfer->raw_data;
        match ($change) {
            'absent' => $raw = [],
            'null' => $raw['nium_approval']['maker_user_id'] = null,
            'string' => $raw['nium_approval']['maker_user_id'] = (string) $transfer->user_id,
            'unknown' => $raw['nium_approval']['maker_user_id'] = 999999,
            'owner' => $raw['nium_approval']['owner_user_id'] = $checker->id,
            'provider' => $raw['nium_approval']['provider_id'] = 999999,
        };
        $transfer->update(['raw_data' => $raw]);
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($checker));
        $this->getJson("/api/admin/transfers/{$transfer->id}")->assertOk()
            ->assertJsonPath('allowed_actions.approve', false);
        $this->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('Missing maker attribution must not authorize dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('maker attribution', $exception->getMessage());
        }
        $this->assertSame($raw, $transfer->fresh()->raw_data);
        $this->assertUnsubmitted($transfer, $calls);
    }

    public static function checkerRevocations(): array
    {
        return ['permission before approval' => ['permission', false], 'inactive before approval' => ['inactive', false],
            'permission after approval' => ['permission', true], 'inactive after approval' => ['inactive', true]];
    }

    #[DataProvider('checkerRevocations')]
    public function test_stale_checker_authority_cannot_approve_retry_or_dispatch(string $change, bool $approved): void
    {
        [$provider, $transfer, $checker] = $this->hkTransfer();
        if ($approved) {
            $transfer = app(TransferApprovalService::class)->approve($transfer, $checker);
        }
        // Cache the formerly valid roles on the caller before revocation.
        $checker->load('roles');
        if ($change === 'permission') {
            $checker->roles()->delete();
        } else {
            User::query()->whereKey($checker->id)->update(['status' => 'inactive']);
        }
        $calls = count(Http::recorded());
        $this->assertFalse(app(NiumSwiftPayoutAuthorization::class)->canApprove($transfer, $checker));
        try {
            app(TransferApprovalService::class)->approve($transfer, $checker, manager: app(ProviderTransferManager::class));
            $this->fail('Revoked authorization must fail before dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('authorized independent checker', $exception->getMessage());
        }
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertStatus($change === 'permission' ? 403 : 422);
        if ($approved) {
            try {
                app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
                $this->fail('Recorded checker authority must be revalidated before dispatch.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('authorized independent checker', $exception->getMessage());
            }
        }
        $this->assertUnsubmitted($transfer, $calls, $approved ? 1 : 0);
    }

    public function test_an_existing_approval_request_cannot_be_bypassed_by_disabling_global_approval(): void
    {
        [$provider, $transfer] = $this->hkTransfer();
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        $calls = count(Http::recorded());
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
            $this->fail('A configuration change cannot waive an existing approval request.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Admin approval is required', $exception->getMessage());
        }
        $this->assertUnsubmitted($transfer, $calls);
    }

    public function test_legacy_approved_swift_without_maker_cannot_resume_or_dispatch(): void
    {
        [$provider, $transfer, $checker] = $this->hkTransfer();
        $approved = app(TransferApprovalService::class)->approve($transfer, $checker);
        $approved->update(['raw_data' => []]);
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($checker));
        $this->getJson("/api/admin/transfers/{$approved->id}")->assertOk()->assertJsonPath('allowed_actions.approve', false);
        $this->postJson("/api/admin/transfers/{$approved->id}/approve")->assertUnprocessable();
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $approved->fresh());
            $this->fail('Legacy attribution cannot be invented for an approved payout.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('maker attribution', $exception->getMessage());
        }
        $this->assertSame([], $approved->fresh()->raw_data);
        $this->assertUnsubmitted($approved, $calls, 1);
    }

    public function test_independent_api_approval_and_uncertain_replay_keep_one_swift_sha_dispatch(): void
    {
        [, $transfer, $checker] = $this->hkTransfer();
        $this->withToken($this->sgdToken($checker));
        $url = "/api/admin/transfers/{$transfer->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('allowed_actions.approve', true);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) {
            if ($request->method() === 'GET' && str_contains($request->url(), 'purposeCodes')) {
                return Http::response([['purposeCode' => 'SYNTHETIC_PURPOSE', 'description' => 'Synthetic only']]);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/remittance')) {
                $this->assertFalse($options['allow_redirects']);
                // Use Laravel's Guzzle connection-failure stub so the attempted POST
                // reaches its recorder. Throwing Laravel ConnectionException here
                // bypasses that recorder even when dispatch authorization succeeded.
                return Http::failedConnection('cURL error 28: Operation timed out; synthetic request, no network');
            }
            throw new RuntimeException('Unexpected synthetic HK provider request');
        });
        $this->postJson($url.'/approve')->assertOk()->assertJsonPath('transfer.status', 'submission_unknown');
        $current = $transfer->fresh();
        $operation = $current->provider_operation_key;
        $this->assertNotNull($operation);
        $this->assertNotNull($current->raw_data['nium_execution']['dispatch_started_at'] ?? null);
        $approval = $current->approvals()->sole();
        $this->assertSame($checker->id, $approval->approver_user_id);
        $this->assertNotSame($current->raw_data['nium_approval']['maker_user_id'], $checker->id);
        $this->postJson($url.'/approve')->assertOk()->assertJsonPath('transfer.status', 'submission_unknown');
        $this->getJson($url)->assertOk()->assertJsonPath('allowed_actions.approve', false);
        $this->assertSame($operation, $transfer->fresh()->provider_operation_key);
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST');
        $this->assertCount(1, $posts);
        $request = $posts->sole()[0];
        $this->assertSame(['beneficiary' => ['id' => 'synthetic-hk-beneficiary'],
            'payout' => ['sourceAmount' => 5, 'sourceCurrency' => 'USD', 'destinationCurrency' => 'USD',
                'payoutMethod' => 'SWIFT', 'tradeOrderID' => 'SWIFT-SECURITY', 'swiftFeeType' => 'SHA'],
            'purposeCode' => 'SYNTHETIC_PURPOSE', 'sourceOfFunds' => 'Corporate Account',
            'customerComments' => 'SWIFT-SECURITY'], $request->data());
        $this->assertTrue($request->hasHeader('x-request-id', substr($operation, 5)));
        $this->assertNull($posts->sole()[1]); // Attempted POST received no response.
        $log = ApiRequestLog::query()->where('related_transfer_id', $transfer->id)->where('operation', 'transfer_money')->sole();
        $this->assertSame($operation, $log->external_reference);
        $this->assertSame('POST', $log->request_method);
        $this->assertSame('timeout_before_response', $log->transport_outcome);
        $this->assertNull($log->response_status);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseCount('transfer_approvals', 1);
    }

    public function test_approved_swift_replay_does_not_introduce_a_resume_path(): void
    {
        [, $transfer, $checker] = $this->hkTransfer();
        $approved = app(TransferApprovalService::class)->approve($transfer, $checker);
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($checker));
        $this->getJson("/api/admin/transfers/{$approved->id}")->assertOk()->assertJsonPath('allowed_actions.approve', false);
        $this->postJson("/api/admin/transfers/{$approved->id}/approve")->assertOk()->assertJsonPath('transfer.status', 'approved');
        $maker = $approved->user;
        $maker->roles()->create(['role_code' => 'admin']);
        $this->withToken($this->sgdToken($maker))->postJson("/api/admin/transfers/{$approved->id}/approve")->assertUnprocessable();
        $this->assertUnsubmitted($approved, $calls, 1);
    }

    public function test_revocation_between_claim_and_final_dispatch_never_sends_a_nium_post(): void
    {
        [, $transfer, $checker] = $this->hkTransfer();
        $approved = app(TransferApprovalService::class)->approve($transfer, $checker);
        $operation = 'nium-'.Str::uuid()->toString();
        $raw = $approved->raw_data;
        $raw['nium_execution'] = ['operation_key' => $operation, 'request_uuid' => substr($operation, 5),
            'dispatch_claimed_at' => now()->toISOString()];
        $approved->update(['provider_operation_key' => $operation, 'raw_data' => $raw]);
        app(LedgerService::class)->reserveTransfer($approved);
        $approved->update(['status' => 'submitting']);
        // The operation and reservation have committed, but POST has not started.
        $checker->roles()->delete();
        $calls = count(Http::recorded());
        try {
            app(NiumService::class)->post('/synthetic-remittance', [], $approved->user,
                $approved->id, 'transfer_money', $operation);
            $this->fail('The final dispatch guard must recheck checker authorization.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('authorized independent checker', $exception->getMessage());
        }
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertNotNull($transfer->fresh()->provider_operation_key);
        $this->assertNull($transfer->fresh()->raw_data['nium_execution']['dispatch_started_at'] ?? null);
        $this->assertSame($calls, count(Http::recorded()));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->withToken($this->sgdToken($checker));
        $this->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertForbidden();
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_admin_creation_records_the_actor_and_neither_actor_nor_owner_can_self_approve(): void
    {
        [, $template, $checker] = $this->hkTransfer();
        $creator = User::factory()->create(['status' => 'active']);
        $creator->roles()->create(['role_code' => 'admin']);
        $this->withToken($this->sgdToken($creator));
        $created = $this->postJson('/api/admin/transfers', [
            'transfer_no' => 'ADMIN-HK-SECURITY', 'user_id' => $template->user_id,
            'provider_id' => $template->provider_id, 'beneficiary_id' => $template->beneficiary_id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => '10', 'fee_amount' => '0', 'fee_currency' => 'USD', 'status' => 'approval_required',
            'purpose_code' => 'SYNTHETIC_PURPOSE', 'reference_text' => 'ADMIN-HK-SECURITY',
            'raw_data' => ['nium_approval' => ['maker_user_id' => $checker->id]],
        ])->assertCreated();
        $transfer = Transfer::query()->findOrFail($created->json('id'));
        $this->assertSame($creator->id, $transfer->raw_data['nium_approval']['maker_user_id']);
        $calls = count(Http::recorded());
        $url = "/api/admin/transfers/{$transfer->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('allowed_actions.approve', false);
        $this->postJson($url.'/approve')->assertUnprocessable();
        $this->putJson($url, ['raw_data' => ['nium_approval' => ['maker_user_id' => $checker->id]]])->assertConflict();
        $owner = $transfer->user;
        $owner->roles()->create(['role_code' => 'admin']);
        $this->withToken($this->sgdToken($owner))->postJson($url.'/approve')->assertUnprocessable();
        $this->assertUnsubmitted($transfer, $calls);
        $this->withToken($this->sgdToken($checker))->postJson($url.'/approve')->assertOk()
            ->assertJsonPath('transfer.status', 'pending');
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_legacy_completed_swift_is_readable_without_settlement_changes(): void
    {
        [, $transfer, $checker] = $this->hkTransfer();
        $transfer->update(['raw_data' => [], 'status' => 'completed', 'completed_at' => now()]);
        $before = $transfer->fresh()->getAttributes();
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($transfer->user));
        $this->getJson("/api/user/users/{$transfer->user_id}/transfers/{$transfer->id}")->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->withToken($this->sgdToken($checker));
        $this->getJson("/api/admin/transfers/{$transfer->id}")->assertOk()->assertJsonPath('allowed_actions.approve', false);
        $this->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        $this->assertSame($before, $transfer->fresh()->getAttributes());
        $this->assertUnsubmitted($transfer, $calls);
    }
}
