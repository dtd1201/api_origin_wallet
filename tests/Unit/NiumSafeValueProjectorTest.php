<?php

namespace Tests\Unit;

use App\Services\Nium\NiumSafeValueProjector;
use App\Support\SensitiveDataSanitizer;
use Tests\TestCase;

class NiumSafeValueProjectorTest extends TestCase
{
    public function test_exact_enum_contract_normalizes_known_values_and_rejects_unknown_or_secret_values(): void
    {
        config()->set('services.nium.auth.header_value', 'configured-status-secret');
        $projector = app(NiumSafeValueProjector::class);

        $this->assertSame('clear', $projector->providerStatus(' CLEAR '));
        $this->assertSame('rfi_requested', $projector->providerSubStatus(' RFI_REQUESTED '));
        $this->assertSame('completed', $projector->complianceStatus('COMPLETED'));
        $this->assertSame('odd_due', $projector->oddStatus('ODD_DUE'));
        $this->assertSame('requested', $projector->rfiStatus('REQUESTED'));
        $this->assertSame('reconciled', $projector->reconciliationStatus('RECONCILED'));
        $this->assertSame('corporate', $projector->customerType('CORPORATE'));
        $this->assertSame('SG', $projector->region(' sg '));
        $this->assertSame('unknown', $projector->providerStatus('person@example.test'));
        $this->assertSame('unknown', $projector->providerStatus('configured-status-secret'));
        $this->assertSame('unknown', $projector->providerSubStatus('https://unsafe.example.test'));
        $this->assertNull($projector->providerSubStatus(''));
    }

    public function test_api_projections_are_explicit_bounded_and_already_sanitizer_stable(): void
    {
        config()->set('services.nium.auth.header_value', 'provider-error-secret');
        $projector = app(NiumSafeValueProjector::class);
        $requestId = '11111111-1111-4111-8111-111111111111';
        $customerId = 'customer-raw-id';
        $walletId = 'wallet-raw-id';

        $request = $projector->apiRequestBody([
            'externalId' => 'external-reference',
            'type' => 'individual',
            'region' => 'UK',
            'email' => 'jane@example.test',
            'documents' => [['fileId' => 'raw-file-id']],
        ]);
        $response = $projector->apiResponseBody([
            'status' => 'clear',
            'subStatus' => '',
            'customerHashId' => $customerId,
            'walletHashId' => $walletId,
            'errors' => [[
                'code' => 'provider-error-secret',
                'field' => 'jane@example.test',
                'path' => '+65 8123 4567',
                'parameter' => 'raw-file-id',
                'description' => 'raw free text',
            ]],
            'rawResponse' => ['unsafe' => true],
        ], 400);

        $this->assertSame([
            'external_id_fingerprint',
            'customer_type',
            'region',
        ], array_keys($request));
        $this->assertSame('unclassified', $response['error_category']);
        $this->assertSame(16, strlen($response['error_fingerprint']));
        $this->assertTrue($response['customer_id_present']);
        $this->assertTrue($response['wallet_id_present']);
        $this->assertSame(16, strlen($response['customer_id_fingerprint']));
        $this->assertSame(16, strlen($response['wallet_id_fingerprint']));
        $this->assertSame(['x-request-id' => $requestId], $projector->apiRequestHeaders($requestId));
        $this->assertSame([], $projector->apiRequestHeaders('not-a-uuid'));
        $this->assertSame('wallet-conflict-001', $projector->requestEvidenceId('wallet-conflict-001'));
        $this->assertNull($projector->requestEvidenceId('unsafe request id with spaces'));
        $this->assertSame($request, app(SensitiveDataSanitizer::class)->sanitize($request));
        $this->assertSame($response, app(SensitiveDataSanitizer::class)->sanitize($response));

        $serialized = json_encode([$request, $response], JSON_THROW_ON_ERROR);
        foreach ([$customerId, $walletId, 'jane@example.test', '+65 8123 4567', 'raw-file-id', 'raw free text', 'provider-error-secret'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }
    }

    public function test_metadata_and_audit_projection_use_only_safe_sources_values_and_fingerprints(): void
    {
        $projector = app(NiumSafeValueProjector::class);
        $metadata = $projector->accountMetadata(
            'clear',
            'rfi_requested',
            'nium_v6_fixture_v2_customer_retry',
            '2026-07-28T01:02:03.000000Z',
            'true',
            [
                'raw-reference-id' => [
                    'kycStatus' => 'submitted',
                    'kycMode' => 'manual_kyc',
                    'entityType' => 'applicant',
                    'updatedAt' => '2026-07-28T01:02:03.000000Z',
                    'raw' => 'must-not-survive',
                ],
            ],
        );
        $audit = $projector->auditState([
            'external_customer_id' => 'customer-raw-id',
            'external_account_id' => 'wallet-raw-id',
            'external_reference' => 'external-reference',
            'status' => 'active',
            'provider_status' => 'clear',
            'provider_sub_status' => null,
            'reconciliation_status' => 'reconciled',
            'integration_status' => 'nium_clear',
        ]);

        $this->assertSame('nium_v6_fixture_v2_customer_retry', $metadata['nium_last_state_source']);
        $this->assertTrue($metadata['is_resubmission_allowed']);
        $this->assertArrayHasKey(
            'ref_'.substr(hash('sha256', 'raw-reference-id'), 0, 16),
            $metadata['nium_entity_kyc_states'],
        );
        $this->assertSame('unknown', $projector->auditSource('runtime free-text source'));
        $this->assertSame(16, strlen($audit['external_customer_id_fingerprint']));
        $this->assertSame(16, strlen($audit['external_account_id_fingerprint']));
        $this->assertSame(16, strlen($audit['external_reference_fingerprint']));
        $this->assertSame('active', $audit['status']);
        $this->assertSame('clear', $audit['provider_status']);
        $this->assertSame('reconciled', $audit['reconciliation_status']);

        $serialized = json_encode([$metadata, $audit], JSON_THROW_ON_ERROR);
        foreach (['raw-reference-id', 'customer-raw-id', 'wallet-raw-id', 'external-reference', 'must-not-survive'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }
    }

    public function test_residual_security_projectors_are_secret_aware_bounded_and_exactly_allowlisted(): void
    {
        $secret = 'configured-residual-secret';
        config()->set('services.nium.auth.header_value', $secret);
        $projector = app(NiumSafeValueProjector::class);
        $diagnostic = 'provider reconciliation mismatch';

        $this->assertSame(
            substr(hash('sha256', $diagnostic), 0, 16),
            $projector->safeOpaqueFingerprint($diagnostic),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{16}$/',
            (string) $projector->safeOpaqueFingerprint($diagnostic),
        );
        $this->assertNull($projector->safeOpaqueFingerprint($secret));
        $this->assertNull($projector->safeOpaqueFingerprint(''));

        foreach (['external_customer_id', 'external_account_id', 'external_reference'] as $field) {
            $this->assertSame($field, $projector->identifierConflictField($field));
        }

        foreach (['', 'metadata', ' external_customer_id ', 'EXTERNAL_CUSTOMER_ID', $secret] as $field) {
            $this->assertNull($projector->identifierConflictField($field));
        }
    }

    public function test_client_capability_projection_is_recursive_allowlisted_and_bounded(): void
    {
        config()->set('services.nium.auth.header_value', 'configured-capability-secret');
        $projection = app(NiumSafeValueProjector::class)->clientCapabilityProjection([
            'region' => 'SG',
            'country' => 'Singapore',
            'clientStatus' => 'ACTIVE',
            'configuration' => [
                'programs' => [
                    ['program' => 'CORPORATE', 'status' => 'ENABLED'],
                    ['program' => 'INDIVIDUAL', 'region' => 'UK'],
                ],
                'supportedKycTypes' => ['FULL', 'MINIMUM'],
            ],
            'currencies' => ['SGD', 'USD', 'EUR'],
            'clientHashId' => 'sensitive-client-id',
            'customerHashId' => 'sensitive-customer-id',
            'email' => 'person@example.test',
            'address' => ['country' => 'must-not-survive'],
            'bankAccountDetails' => ['currency' => 'must-not-survive'],
            'token' => 'configured-capability-secret',
            'unknown' => 'arbitrary-value',
            'market' => str_repeat('x', 97),
            'markets' => range(1, 9),
            'kycTypes' => ['FULL', ['unsafe' => true]],
            'program' => '12345678901234567890123456789012',
        ]);

        $this->assertSame([
            'clientStatus' => 'ACTIVE',
            'country' => 'Singapore',
            'currencies' => ['SGD', 'USD', 'EUR'],
            'programs.0.program' => 'CORPORATE',
            'programs.0.status' => 'ENABLED',
            'programs.1.program' => 'INDIVIDUAL',
            'programs.1.region' => 'UK',
            'region' => 'SG',
            'supportedKycTypes' => ['FULL', 'MINIMUM'],
        ], $projection);

        $serialized = json_encode($projection, JSON_THROW_ON_ERROR);

        foreach (['sensitive-', 'person@example.test', 'must-not-survive', 'configured-capability-secret', 'arbitrary-value'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }
    }
}
