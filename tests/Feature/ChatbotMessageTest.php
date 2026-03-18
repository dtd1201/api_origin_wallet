<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_message_requires_authentication(): void
    {
        $response = $this->postJson('/api/auth/chatbot/message', [
            'message' => 'How do I add a beneficiary?',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_chatbot_message_returns_reply_and_actions(): void
    {
        $user = User::factory()->create([
            'full_name' => 'Jane Doe',
        ]);

        $plainToken = 'test-token-123';

        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->postJson('/api/auth/chatbot/message', [
            'message' => 'How do I add a beneficiary?',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'conversation_id',
                'reply',
                'suggestions',
                'actions' => [
                    '*' => ['type', 'label', 'target'],
                ],
                'meta' => [
                    'profile_completed',
                    'has_provider_account',
                    'has_beneficiaries',
                    'has_balances',
                ],
            ]);

        $response->assertJsonPath('actions.0.type', 'navigate');
    }
}
