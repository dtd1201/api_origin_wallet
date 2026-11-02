<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\NiumSgdLocalPayoutAuthorization;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumPayoutAuthorizationSecurityTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    public static function routingChanges(): array
    {
        return ['account type' => ['type'], 'typed routing' => ['routing'], 'proxy recipient' => ['proxy'],
            'additional routing field' => ['additional'], 'effective request override' => ['override'],
            'individual to corporate recipient' => ['entity']];
    }

    #[DataProvider('routingChanges')]
    public function test_effective_provider_recipient_changes_block_approval_without_side_effects(string $change): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $accepted = $this->submitSgd($transfer);
        $receipt = $accepted->raw_data['sgd_local_workflow']['customer_authorization'];
        $this->assertSame(2, $receipt['instruction']['receiving_account']['binding_version']);
        $beneficiary = $accepted->beneficiary;
        $raw = $beneficiary->raw_data ?? [];
        match ($change) {
            'type' => $raw['nium']['beneficiaryBankAccountType'] = 'Savings',
            'routing' => $raw['nium']['routingInfo'] = [['type' => 'SWIFT', 'value' => 'OTHERBIC']],
            'proxy' => $raw['nium']['proxyValue'] = 'different-proxy',
            'additional' => $raw['nium']['additionalFields']['beneficiaryBranchCode'] = 'different-branch',
            'override' => $raw['nium']['request']['beneficiaryAccountNumber'] = 'different-account',
            'entity' => $beneficiary->beneficiary_type = 'business',
        };
        $beneficiary->raw_data = $raw;
        $beneficiary->save();
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($checker));
        $this->getJson("/api/admin/transfers/{$accepted->id}")->assertOk()
            ->assertJsonPath('allowed_actions.approve', false)
            ->assertJsonPath('payout_workflow.checker_approval_allowed', false);
        $this->postJson("/api/admin/transfers/{$accepted->id}/approve")->assertUnprocessable();
        $this->assertSame($receipt, $accepted->fresh()->raw_data['sgd_local_workflow']['customer_authorization']);
        $this->assertNull($accepted->fresh()->provider_operation_key);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame($calls, count(Http::recorded()));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
    }

    public function test_equivalent_optional_inputs_aliases_and_unrelated_metadata_preserve_acceptance(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $raw = ['nium' => ['payoutMethod' => 'LOCAL', 'routingInfo' => [['type' => 'SWIFT', 'value' => 'DBSSSGSGXXX']],
            'beneficiaryBankAccountType' => null, 'additionalFields' => ['beneficiaryBranchCode' => '']]];
        $transfer->beneficiary->update(['account_number' => '0012345678', 'raw_data' => $raw]);
        $instruction = app(NiumSgdLocalPayoutAuthorization::class)->customerInstruction($transfer);
        $accepted = $this->submitSgd($transfer);
        // Resolve the same provider-facing values through the supported legacy aliases.
        $raw['nium']['payout_method'] = 'local';
        unset($raw['nium']['payoutMethod']);
        $raw['nium']['routing_info'] = [['value' => 'DBSSSGSGXXX', 'type' => 'SWIFT']];
        unset($raw['nium']['routingInfo'], $raw['nium']['beneficiaryBankAccountType']);
        $raw['nium']['additionalFields']['beneficiaryBranchCode'] = null;
        $raw['nium']['beneficiary_preflight'] = ['checked_at' => now()->toISOString(), 'diagnostic' => 'changed'];
        $raw['nium']['provider_verified'] = ['payout_method' => 'LOCAL', 'observed_at' => now()->toISOString()];
        $raw['provider_request_id'] = 'new-diagnostic';
        $raw['origin']['transaction_document_name'] = 'unrelated.pdf';
        $accepted->beneficiary->update(['bank_name' => '', 'raw_data' => $raw]);
        $this->assertSame($instruction, app(NiumSgdLocalPayoutAuthorization::class)->customerInstruction($accepted));
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$accepted->id}/approve")
            ->assertOk()->assertJsonPath('transfer.status', 'pending');
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance')));
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_provider_binding_preserves_significant_account_values_and_ignores_metadata(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $service = app(NiumBeneficiaryService::class);
        $beneficiary = $transfer->beneficiary;
        $beneficiary->account_number = '000123';
        $original = $service->receivingAccountBinding($beneficiary);
        foreach ([[], ['nium' => ['beneficiaryBankAccountType' => null]], ['nium' => ['beneficiaryBankAccountType' => '']]] as $raw) {
            $beneficiary->raw_data = $raw;
            $this->assertSame($original, $service->receivingAccountBinding($beneficiary));
        }
        $this->assertSame('000123', $original['beneficiaryAccountNumber']);
        $beneficiary->account_number = '123';
        $this->assertNotSame($original, $service->receivingAccountBinding($beneficiary));
    }

    public static function oldAuthorizationStages(): array
    {
        return ['awaiting checker' => [false], 'approved before dispatch' => [true]];
    }

    private function legacyReceipt(Transfer $transfer): array
    {
        $raw = $transfer->raw_data;
        $instruction = $raw['sgd_local_workflow']['customer_authorization']['instruction'];
        unset($instruction['receiving_account']['binding_version']);
        $account = $transfer->beneficiary->only(['full_name', 'company_name', 'account_number', 'iban', 'bank_name',
            'bank_code', 'branch_code', 'swift_bic', 'country_code', 'currency', 'payout_method']);
        $instruction['receiving_account']['sha256'] = hash('sha256', json_encode($account, JSON_THROW_ON_ERROR));
        $raw['sgd_local_workflow']['customer_authorization']['instruction'] = $instruction;
        $raw['sgd_local_workflow']['customer_authorization']['submission_token'] =
            NiumSgdLocalPayoutWorkflow::financialTermsToken($instruction);

        return $raw;
    }

    #[DataProvider('oldAuthorizationStages')]
    public function test_historical_binding_cannot_be_silently_upgraded_to_authorize_dispatch(bool $approved): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $transfer = $this->submitSgd($transfer);
        if ($approved) {
            $transfer = app(TransferApprovalService::class)->approve($transfer, $checker);
        }
        $raw = $this->legacyReceipt($transfer);
        $transfer->update(['raw_data' => $raw]);
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($checker));
        $this->getJson("/api/admin/transfers/{$transfer->id}")->assertOk()
            ->assertJsonPath('allowed_actions.approve', false);
        $this->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        $this->assertSame($raw, $transfer->fresh()->raw_data);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', $approved ? 1 : 0);
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_changed_account_blocks_direct_dispatch_even_with_a_previously_loaded_relation(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $approved = app(TransferApprovalService::class)->approve($this->submitSgd($transfer), $checker);
        $approved->load('beneficiary');
        $raw = $approved->beneficiary->raw_data ?? [];
        $raw['nium']['beneficiaryBankAccountType'] = 'Savings';
        Beneficiary::query()->findOrFail($approved->beneficiary_id)->update(['raw_data' => $raw]);
        $calls = count(Http::recorded());
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $approved);
            $this->fail('Stale receiving details must not reach financial admission or dispatch.');
        } catch (RuntimeException|ValidationException $exception) {
            $this->assertStringContainsString('acceptance', $exception->getMessage());
        }
        $this->assertNull($approved->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_completed_legacy_sgd_remains_readable_without_rewriting_or_redispatch(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $transfer = $this->submitSgd($transfer);
        $raw = $this->legacyReceipt($transfer);
        $raw['sgd_local_workflow']['state'] = 'completed';
        $transfer->update(['raw_data' => $raw, 'status' => 'completed', 'completed_at' => now()]);
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($transfer->user));
        $this->getJson("/api/user/users/{$transfer->user_id}/transfers/{$transfer->id}")->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->withToken($this->sgdToken($checker));
        $this->getJson("/api/admin/transfers/{$transfer->id}")->assertOk()
            ->assertJsonPath('allowed_actions.approve', false);
        $this->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        $this->assertSame($raw, $transfer->fresh()->raw_data);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame($calls, count(Http::recorded()));
    }
}
