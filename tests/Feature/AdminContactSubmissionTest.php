<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ContactSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminContactSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_contact_submissions(): void
    {
        $admin = $this->createAdminUser();

        ContactSubmission::query()->create([
            'name' => 'Older Sender',
            'email' => 'older@example.com',
            'company' => 'Older Co',
            'subject' => 'Older subject',
            'message' => 'Older message',
            'submitted_at' => now()->subDay(),
        ]);

        $latestSubmission = ContactSubmission::query()->create([
            'name' => 'Latest Sender',
            'email' => 'latest@example.com',
            'company' => 'Latest Co',
            'subject' => 'Latest subject',
            'message' => 'Latest message',
            'submitted_at' => now(),
        ]);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->getJson('/api/admin/contact-submissions');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $latestSubmission->id)
            ->assertJsonPath('data.0.email', 'latest@example.com')
            ->assertJsonPath('per_page', 15);
    }

    public function test_admin_can_view_contact_submission_detail(): void
    {
        $admin = $this->createAdminUser();
        $submission = ContactSubmission::query()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'company' => 'Origin Wallet',
            'subject' => 'Need help',
            'message' => 'Please contact me.',
            'submitted_at' => now(),
        ]);

        $this->withToken($this->issueTokenFor($admin))
            ->getJson("/api/admin/contact-submissions/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('id', $submission->id)
            ->assertJsonPath('message', 'Please contact me.');
    }

    public function test_non_admin_cannot_access_contact_submission_admin_api(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->issueTokenFor($user))
            ->getJson('/api/admin/contact-submissions')
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to access admin resources.');
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->create([
            'role_code' => 'admin',
        ]);

        return $user;
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
