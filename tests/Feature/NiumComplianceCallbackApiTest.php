<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumComplianceEvent;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NiumComplianceCallbackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nium.compliance_callback.static_header_name', 'x-partner-key');
        config()->set('services.nium.compliance_callback.static_header_value', 'nium-compliance-test-key');
    }

    public function test_compliance_callback_marks_matching_transfer_for_action_required_review(): void
    {
        $provider = $this->provider();
        $transfer = $this->transfer($provider, 'NIUM-COMP-1001');

        $response = $this->postComplianceCallback([
            'eventId' => 'compliance-event-1001',
            'eventType' => 'TRANSACTION_COMPLIANCE_STATUS',
            'status' => 'ACCEPTED',
            'data' => [
                'systemReferenceNumber' => 'NIUM-COMP-1001',
                'complianceStatus' => 'ACTION_REQUIRED',
            ],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('match_status', 'matched_transfer')
            ->assertJsonPath('review_status', 'pending');

        $transfer->refresh();
        $this->assertTrue($transfer->compliance_review_required);
        $this->assertSame('ACTION_REQUIRED', $transfer->compliance_status);
        $this->assertDatabaseHas('nium_compliance_events', [
            'event_id' => 'compliance-event-1001',
            'transfer_id' => $transfer->id,
            'requires_action' => true,
            'review_status' => 'pending',
            'processing_status' => 'processed',
        ]);
    }

    public function test_compliance_callback_is_idempotent_for_duplicate_event(): void
    {
        $provider = $this->provider();
        $this->transfer($provider, 'NIUM-COMP-2001');
        $payload = [
            'eventId' => 'compliance-event-duplicate',
            'data' => [
                'systemReferenceNumber' => 'NIUM-COMP-2001',
                'status' => 'ACTION_REQUIRED',
            ],
        ];

        $this->postComplianceCallback($payload)->assertAccepted()->assertJsonPath('duplicate', false);
        $this->postComplianceCallback($payload)->assertAccepted()->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('nium_compliance_events', 1);
        $this->assertDatabaseHas('nium_compliance_events', [
            'event_id' => 'compliance-event-duplicate',
            'duplicate_count' => 1,
        ]);
    }

    public function test_unmatched_compliance_callback_is_kept_as_pending_admin_task(): void
    {
        $this->provider();

        $response = $this->postComplianceCallback([
            'eventId' => 'compliance-event-unmatched',
            'eventType' => 'TRANSACTION_COMPLIANCE_STATUS',
            'data' => [
                'systemReferenceNumber' => 'UNKNOWN-NIUM-REFERENCE',
                'customerHashId' => 'UNKNOWN-NIUM-CUSTOMER',
                'complianceStatus' => 'ACTION_REQUIRED',
            ],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('matched', false)
            ->assertJsonPath('match_status', 'unmatched')
            ->assertJsonPath('review_status', 'pending');

        $this->assertDatabaseHas('nium_compliance_events', [
            'event_id' => 'compliance-event-unmatched',
            'reference' => 'UNKNOWN-NIUM-REFERENCE',
            'customer_reference' => 'UNKNOWN-NIUM-CUSTOMER',
            'match_status' => 'unmatched',
            'review_status' => 'pending',
        ]);
    }

    public function test_compliance_callback_rejects_missing_or_incorrect_static_partner_key(): void
    {
        $this->provider();
        $payload = ['eventId' => 'unauthorized-compliance-event'];

        $this->postJson('/api/callbacks/nium/transaction-compliance', $payload)
            ->assertForbidden();

        $this->withHeader('x-partner-key', 'wrong-key')
            ->postJson('/api/callbacks/nium/transaction-compliance', $payload)
            ->assertForbidden();

        $this->assertDatabaseMissing('nium_compliance_events', [
            'event_id' => 'unauthorized-compliance-event',
        ]);
    }

    public function test_admin_can_review_pending_compliance_task_and_clear_transfer_flag(): void
    {
        $provider = $this->provider();
        $transfer = $this->transfer($provider, 'NIUM-COMP-3001');
        $eventId = $this->postComplianceCallback([
            'eventId' => 'compliance-event-admin-review',
            'data' => [
                'systemReferenceNumber' => 'NIUM-COMP-3001',
                'complianceStatus' => 'ACTION_REQUIRED',
            ],
        ])->assertAccepted()->json('event_id');
        $event = NiumComplianceEvent::query()->where('event_id', $eventId)->firstOrFail();
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $token = $this->issueTokenFor($admin);

        $this->withToken($token)
            ->getJson('/api/admin/nium-compliance-events?review_status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.event_id', 'compliance-event-admin-review');

        $this->withToken($token)
            ->postJson("/api/admin/nium-compliance-events/{$event->id}/review", [
                'status' => 'resolved',
                'resolution_note' => 'Required information was supplied to Nium.',
            ])
            ->assertOk()
            ->assertJsonPath('event.review_status', 'resolved');

        $transfer->refresh();
        $this->assertFalse($transfer->compliance_review_required);
        $this->assertNotNull($transfer->compliance_reviewed_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'nium_compliance_event.reviewed',
            'entity_type' => 'nium_compliance_event',
            'entity_id' => (string) $event->id,
        ]);
    }

    private function postComplianceCallback(array $payload): TestResponse
    {
        return $this->withHeader('x-partner-key', 'nium-compliance-test-key')
            ->postJson('/api/callbacks/nium/transaction-compliance', $payload);
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
    }

    private function transfer(IntegrationProvider $provider, string $externalReference): Transfer
    {
        $user = User::factory()->create();

        return Transfer::query()->create([
            'transfer_no' => 'TRF-'.str_replace('NIUM-COMP-', '', $externalReference),
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_transfer_id' => $externalReference,
            'transfer_type' => 'local',
            'source_currency' => 'USD',
            'target_currency' => 'EUR',
            'source_amount' => 100,
            'target_amount' => 90,
            'status' => 'pending',
        ]);
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);

        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
