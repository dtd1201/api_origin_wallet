<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\NiumApiExchangeEvidence;
use App\Services\Nium\DynamicConfirmation\DynamicRegisteredAccountEvidence;
use App\Services\Nium\NiumBeneficiaryService;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

final class NiumDynamicRegisteredAccountEvidenceTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;

    private function registration(?string $customer = 'fixture-customer'): array
    {
        $this->beneficiary->update(['account_number' => '0012345678', 'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']]]);
        $request = json_encode(app(NiumBeneficiaryService::class)->receivingAccountBinding($this->beneficiary), JSON_THROW_ON_ERROR);
        $response = json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id,
            'customerHashId' => $customer], JSON_THROW_ON_ERROR);
        $log = ApiRequestLog::query()->create(['user_id' => $this->owner->id, 'provider_id' => $this->account->provider_id,
            'operation' => 'beneficiary_create', 'client_hash_id' => 'fixture-client', 'request_method' => 'POST',
            'request_url' => 'https://gatewaysandbox.nium.com/api/v2/client/[REDACTED]/customer/[REDACTED]/beneficiaries',
            'is_success' => true, 'response_status' => 200, 'created_at' => now()]);
        NiumApiExchangeEvidence::query()->create(['api_request_log_id' => $log->id, 'request_method' => 'POST',
            'request_url' => $log->request_url, 'raw_request_body' => $request, 'raw_response_body' => $response,
            'request_body_sha256' => hash('sha256', $request), 'response_body_sha256' => hash('sha256', $response),
            'capture_version' => 1, 'response_received' => true]);
        return ['registration_log_id' => $log->id, 'client' => 'fixture-client',
            'registration_request_sha256' => hash('sha256', $request), 'registration_response_sha256' => hash('sha256', $response)];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures();
    }

    public function test_full_original_payload_and_customer_identity_bind_the_registered_account(): void
    {
        $binding = $this->registration();
        $result = app(DynamicRegisteredAccountEvidence::class)->inspect($this->beneficiary, $this->account, $binding);
        $this->assertTrue($result['payload_matches']);
        $this->assertTrue($result['customer_scope_confirmed']);
        $this->assertSame([], $result['reason_codes']);
        $this->assertSame($binding['registration_request_sha256'], $result['registered_request_sha256']);
        // Leading zeroes are material account data, not numeric formatting.
        $this->beneficiary->update(['account_number' => '12345678']);
        $changed = app(DynamicRegisteredAccountEvidence::class)->inspect($this->beneficiary, $this->account, $binding);
        $this->assertSame(['dynamic_registered_account_payload_changed'], $changed['reason_codes']);
        $this->assertNotSame($result['current_record_sha256'], $changed['current_record_sha256']);
    }

    public function test_missing_customer_scope_is_not_inferred_from_a_redacted_url_or_verified_flag(): void
    {
        $binding = $this->registration(null);
        $result = app(DynamicRegisteredAccountEvidence::class)->inspect($this->beneficiary, $this->account, $binding);
        $this->assertTrue($result['payload_matches']);
        $this->assertSame(['dynamic_registered_account_customer_scope_unavailable'], $result['reason_codes']);
    }

    public function test_withdrawn_original_exchange_is_rejected(): void
    {
        $binding = $this->registration();
        ApiRequestLog::query()->findOrFail($binding['registration_log_id'])->update(['is_success' => false]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dynamic_quote_registration_evidence_unavailable');
        app(DynamicRegisteredAccountEvidence::class)->inspect($this->beneficiary, $this->account, $binding);
    }
}
