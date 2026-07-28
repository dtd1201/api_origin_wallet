<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumProviderAccountStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class NiumProviderStateSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_state_keeps_dedicated_ids_and_only_safe_account_submission_and_audit_projections(): void
    {
        [$provider, $user, $account] = $this->account();
        $submission = $user->kycProviderSubmissions()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'metadata' => ['unsafe_sibling' => 'must-be-removed'],
        ]);
        $unrelated = UserProviderAccount::query()->create([
            'user_id' => User::factory()->create()->id,
            'provider_id' => $provider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
            'metadata' => ['unrelated' => 'unchanged'],
        ]);
        $unrelatedBefore = UserProviderAccount::query()
            ->findOrFail($unrelated->id)
            ->getAttributes();
        $customerId = '11111111-1111-4111-8111-111111111111';
        $walletId = '22222222-2222-4222-8222-222222222222';
        $externalReference = '33333333-3333-4333-8333-333333333333';
        $rawEntityReference = '44444444-4444-4444-8444-444444444444';

        $result = app(NiumProviderAccountStateService::class)->applyAuthenticatedState(
            $account,
            [
                'customerHashId' => $customerId,
                'walletHashId' => $walletId,
                'externalId' => $externalReference,
                'status' => ' CLEAR ',
                'subStatus' => ' RFI_REQUESTED ',
                'complianceStatus' => ' COMPLETED ',
                'oddStatus' => ' ODD_DUE ',
                'isResubmissionAllowed' => 'true',
                'referenceId' => $rawEntityReference,
                'kycStatus' => 'SUBMITTED',
                'kycMode' => 'MANUAL_KYC',
                'entityType' => 'APPLICANT',
            ],
            'nium_v5_customer_get_response',
            '203.0.113.10',
            'Jane jane@example.test Bearer secret',
            'not-a-uuid-request-id',
        );

        $audit = AuditLog::query()
            ->where('action', 'provider_account.nium_state_changed')
            ->sole();
        $serializedAudit = json_encode([$audit->old_data, $audit->new_data], JSON_THROW_ON_ERROR);

        $this->assertSame($customerId, $result->external_customer_id);
        $this->assertSame($walletId, $result->external_account_id);
        $this->assertSame($externalReference, $result->external_reference);
        $this->assertSame('clear', $result->provider_status);
        $this->assertSame('rfi_requested', $result->provider_sub_status);
        $this->assertSame('completed', $result->compliance_status);
        $this->assertSame('odd_due', $result->odd_status);
        $this->assertSame('requested', $result->rfi_status);
        $this->assertSame([
            'integration_status',
            'nium_last_state_source',
            'nium_last_state_at',
            'is_resubmission_allowed',
            'nium_entity_kyc_states',
        ], array_keys($result->metadata));
        $this->assertSame('nium_clear_rfi_requested', $result->metadata['integration_status']);
        $this->assertSame('nium_v5_customer_get_response', $result->metadata['nium_last_state_source']);
        $this->assertTrue($result->metadata['is_resubmission_allowed']);
        $entityKey = 'ref_'.substr(hash('sha256', $rawEntityReference), 0, 16);
        $this->assertSame([
            'kyc_status' => 'submitted',
            'kyc_mode' => 'manual_kyc',
            'entity_type' => 'applicant',
            'updated_at' => $result->metadata['nium_entity_kyc_states'][$entityKey]['updated_at'],
        ], $result->metadata['nium_entity_kyc_states'][$entityKey]);

        $submission->refresh();
        $this->assertSame('submitted', $submission->status);
        $this->assertSame([
            'provider_status' => 'clear',
            'provider_sub_status' => 'rfi_requested',
            'compliance_status' => 'completed',
            'rfi_status' => 'requested',
            'odd_status' => 'odd_due',
        ], $submission->metadata);

        $this->assertSame('nium_v5_customer_get_response', $audit->new_data['source']);
        $this->assertNull($audit->ip_address);
        $this->assertNull($audit->user_agent);
        $this->assertDatabaseCount('audit_logs', 1);
        foreach ([$customerId, $walletId, $externalReference, $rawEntityReference, 'jane@example.test', 'not-a-uuid-request-id'] as $raw) {
            $this->assertStringNotContainsString($raw, $serializedAudit);
        }
        $unrelatedAfter = UserProviderAccount::query()
            ->findOrFail($unrelated->id)
            ->getAttributes();
        $this->assertSame($unrelatedBefore, $unrelatedAfter);
    }

    public function test_unknown_and_configured_secret_provider_values_fail_closed_in_rows_metadata_and_audit(): void
    {
        [, $user, $account] = $this->account();
        $statusSecret = 'configured-status-secret';
        $subStatusSecret = 'configured-substatus-secret';
        config()->set('services.nium.auth.header_value', $statusSecret);
        config()->set('services.nium.auth.client_secret', $subStatusSecret);
        $account->update([
            'provider_status' => $statusSecret,
            'provider_sub_status' => 'person@example.test',
            'compliance_status' => 'https://unsafe.example.test',
            'odd_status' => '+65 8123 4567',
            'metadata' => [
                'integration_status' => $statusSecret,
                'raw_provider_response' => ['secret' => $subStatusSecret],
            ],
        ]);
        $user->kycProviderSubmissions()->create([
            'provider_id' => $account->provider_id,
            'status' => 'pending',
            'metadata' => ['raw_identifier' => 'customer-secret-id'],
        ]);

        $result = app(NiumProviderAccountStateService::class)->applyAuthenticatedState(
            $account,
            [
                'status' => $statusSecret,
                'subStatus' => $subStatusSecret,
                'complianceStatus' => 'free text status',
                'oddStatus' => 'person@example.test',
            ],
            'runtime caller source jane@example.test',
            userAgent: 'raw-user-agent-secret',
            requestId: 'raw-request-id-secret',
        );

        $audit = AuditLog::query()->sole();
        $serialized = json_encode([
            $result->toArray(),
            $user->kycProviderSubmissions()->sole()->toArray(),
            $audit->toArray(),
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('unknown', $result->provider_status);
        $this->assertSame('unknown', $result->provider_sub_status);
        $this->assertSame('unknown', $result->compliance_status);
        $this->assertSame('unknown', $result->odd_status);
        $this->assertSame('unknown', $audit->old_data['provider_status']);
        $this->assertSame('unknown', $audit->new_data['provider_status']);
        $this->assertSame('unknown', $audit->new_data['source']);
        $this->assertSame('nium_unknown_unknown', $result->metadata['integration_status']);
        $this->assertSame([
            'integration_status',
            'nium_last_state_source',
            'nium_last_state_at',
        ], array_keys($result->metadata));
        $this->assertSame([
            'provider_status' => 'unknown',
            'provider_sub_status' => 'unknown',
            'compliance_status' => 'unknown',
            'odd_status' => 'unknown',
        ], $user->kycProviderSubmissions()->sole()->metadata);

        foreach ([
            $statusSecret,
            $subStatusSecret,
            'person@example.test',
            'https://unsafe.example.test',
            '+65 8123 4567',
            'free text status',
            'runtime caller source jane@example.test',
            'raw-user-agent-secret',
            'raw-request-id-secret',
            'customer-secret-id',
        ] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }
    }

    public function test_established_no_state_change_path_writes_zero_audits(): void
    {
        [, , $account] = $this->account();
        $account->update([
            'metadata' => [
                'integration_status' => 'nium_pending',
                'nium_last_state_source' => 'nium_v5_customer_get_response',
                'nium_last_state_at' => now()->toISOString(),
            ],
        ]);

        app(NiumProviderAccountStateService::class)->recordVerifiedNotificationDetails(
            $account,
            [],
            'nium_v5_customer_get_response',
        );

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('user_provider_accounts', 1);
    }

    public function test_reconciliation_failure_persists_only_fixed_category_and_safe_bounded_fingerprint(): void
    {
        [, , $account] = $this->account();
        $reason = 'provider reconciliation mismatch';

        $result = app(NiumProviderAccountStateService::class)->markReconciliationFailure(
            $account,
            $reason,
            'nium_v5_customer_get_response',
        );

        $audit = AuditLog::query()
            ->where('action', 'provider_account.nium_reconciliation_failed')
            ->sole();
        $serialized = json_encode([$result->toArray(), $audit->toArray()], JSON_THROW_ON_ERROR);

        $this->assertSame('failed', $result->reconciliation_status);
        $this->assertSame('reconciliation_failed', $result->reconciliation_error);
        $this->assertSame('reconciliation_failed', $audit->new_data['reason_category']);
        $this->assertSame(
            substr(hash('sha256', $reason), 0, 16),
            $audit->new_data['reason_fingerprint'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $audit->new_data['reason_fingerprint']);
        $this->assertStringNotContainsString($reason, $serialized);
        $this->assertStringNotContainsString('reconciliation_error\":\"'.$reason, $serialized);
    }

    public function test_reconciliation_failure_rejects_configured_secret_fingerprint_and_never_persists_raw_reason_forms(): void
    {
        [$provider, , $secretAccount] = $this->account();
        $secret = 'configured-reconciliation-secret';
        $unsafeReason = 'jane@example.test +65 8123 4567 https://unsafe.example.test '
            .'eyJhbGciOiJIUzI1NiJ9.payload.signature x-api-key=raw-api-key free diagnostic text';
        config()->set('services.nium.auth.header_value', $secret);
        $unsafeAccount = $this->accountForProvider($provider);
        $service = app(NiumProviderAccountStateService::class);

        $service->markReconciliationFailure(
            $secretAccount,
            $secret,
            'nium_v5_customer_get_response',
        );
        $service->markReconciliationFailure(
            $unsafeAccount,
            $unsafeReason,
            'nium_v5_customer_get_response',
        );

        $secretAudit = AuditLog::query()
            ->where('entity_id', (string) $secretAccount->id)
            ->where('action', 'provider_account.nium_reconciliation_failed')
            ->sole();
        $serialized = json_encode([
            UserProviderAccount::query()->findOrFail($secretAccount->id)->toArray(),
            UserProviderAccount::query()->findOrFail($unsafeAccount->id)->toArray(),
            AuditLog::query()->get()->toArray(),
        ], JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('reason_fingerprint', $secretAudit->new_data);
        $this->assertNull($secretAudit->new_data['reason_fingerprint']);
        $this->assertDatabaseCount('audit_logs', 2);
        foreach ([
            $secret,
            'jane@example.test',
            '+65 8123 4567',
            'https://unsafe.example.test',
            'eyJhbGciOiJIUzI1NiJ9.payload.signature',
            'raw-api-key',
            'free diagnostic text',
        ] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }
    }

    public function test_each_allowlisted_identifier_conflict_field_remains_functional_and_audited_as_a_literal(): void
    {
        [$provider, , $firstAccount] = $this->account();
        $accounts = [
            'external_customer_id' => $firstAccount,
            'external_account_id' => $this->accountForProvider($provider),
            'external_reference' => $this->accountForProvider($provider),
        ];
        $service = app(NiumProviderAccountStateService::class);

        foreach ($accounts as $field => $account) {
            $result = $service->quarantineIdentifierConflict(
                $account,
                $field,
                'current-value',
                'incoming-value',
                'nium_v5_customer_get_response',
            );
            $audit = AuditLog::query()
                ->where('entity_id', (string) $account->id)
                ->where('action', 'provider_account.nium_security_conflict')
                ->sole();

            $this->assertSame('blocked', $result->status);
            $this->assertSame($field.'_mismatch', $result->security_conflict_reason);
            $this->assertSame($field, $audit->new_data['conflicting_field']);
            $this->assertSame(substr(hash('sha256', 'current-value'), 0, 16), $audit->new_data['current_fingerprint']);
            $this->assertSame(substr(hash('sha256', 'incoming-value'), 0, 16), $audit->new_data['incoming_fingerprint']);
        }

        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_invalid_and_secret_identifier_conflict_fields_are_rejected_before_dml(): void
    {
        [, , $account] = $this->account();
        $secret = 'configured-conflict-field-secret';
        config()->set('services.nium.auth.header_value', $secret);
        $before = UserProviderAccount::query()->findOrFail($account->id)->getAttributes();
        $dml = [];

        DB::listen(function ($query) use (&$dml): void {
            if (preg_match('/^\\s*(insert|update|delete)\\b/i', $query->sql) === 1) {
                $dml[] = $query->sql;
            }
        });

        foreach (['metadata', $secret] as $field) {
            try {
                app(NiumProviderAccountStateService::class)->quarantineIdentifierConflict(
                    $account,
                    $field,
                    'current-value',
                    'incoming-value',
                    'nium_v5_customer_get_response',
                );
                $this->fail('Expected invalid identifier conflict field rejection.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid Nium identifier conflict field.', $exception->getMessage());
                $this->assertStringNotContainsString($field, $exception->getMessage());
            }
        }

        $after = UserProviderAccount::query()->findOrFail($account->id)->getAttributes();
        $this->assertSame($before, $after);
        $this->assertSame([], $dml);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function account(): array
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
            'reconciliation_status' => 'pending',
            'metadata' => [
                'integration_status' => 'nium_pending',
                'unsafe_sibling' => 'must-be-removed',
            ],
        ]);

        return [$provider, $user, $account];
    }

    private function accountForProvider(IntegrationProvider $provider): UserProviderAccount
    {
        return User::factory()->create()->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
            'reconciliation_status' => 'pending',
            'metadata' => ['integration_status' => 'nium_pending'],
        ]);
    }
}
