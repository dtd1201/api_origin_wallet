<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_submission_can_be_created(): void
    {
        $response = $this
            ->withHeader('User-Agent', 'Frontend Contact Form')
            ->postJson('/api/contact', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'company' => 'Origin Wallet',
                'subject' => 'Need help with onboarding',
                'message' => 'Please contact me about account setup.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Contact message submitted successfully.')
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'submitted_at'],
            ]);

        $this->assertDatabaseHas('contact_submissions', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'company' => 'Origin Wallet',
            'subject' => 'Need help with onboarding',
            'message' => 'Please contact me about account setup.',
            'user_agent' => 'Frontend Contact Form',
        ]);
    }

    public function test_contact_submission_requires_required_fields(): void
    {
        $response = $this->postJson('/api/contact', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'email',
                'subject',
                'message',
            ]);
    }
}
