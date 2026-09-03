<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IdentityVerificationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IdentityVerificationTrustBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_cannot_submit_scores_or_authoritative_state(): void
    {
        $user = User::factory()->create();
        $session = $this->sessionFor($user);

        $this->withToken($this->issueTokenFor($user))
            ->postJson($this->completeUrl($user, $session), [
                'liveness_score' => 100,
                'face_match_score' => 100,
                'status' => 'verified',
                'checks' => ['session' => 'completed'],
                'document_ocr' => ['result' => 'approved'],
                'completed_at' => now()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'liveness_score',
                'face_match_score',
                'status',
                'checks',
                'document_ocr',
                'completed_at',
            ]);

        $session->refresh();
        $this->assertSame('in_progress', $session->status);
        $this->assertNull($session->liveness_score);
        $this->assertNull($session->face_match_score);
        $this->assertNull($session->completed_at);
    }

    public function test_uploaded_liveness_evidence_is_submitted_without_claiming_success(): void
    {
        Storage::fake('kyc_private');
        config()->set('services.identity_verification.evidence_disk', 'kyc_private');

        $user = User::factory()->create();
        $session = $this->sessionFor($user, 'created');
        $token = $this->issueTokenFor($user);

        $upload = $this->withToken($token)
            ->post($this->uploadUrl($user, $session), [
                'capture_type' => 'selfie_liveness',
                'file' => UploadedFile::fake()->createWithContent(
                    'selfie.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
                ),
                'metadata' => ['evidence_type' => 'guided_liveness_image'],
            ]);

        $upload
            ->assertOk()
            ->assertJsonPath('session.status', 'in_progress')
            ->assertJsonPath('artifact.storage_disk', 'kyc_private');
        Storage::disk('kyc_private')->assertExists($upload->json('artifact.file_path'));

        $this->withToken($token)
            ->postJson($this->completeUrl($user, $session), [])
            ->assertAccepted()
            ->assertJsonPath('session.status', 'submitted')
            ->assertJsonPath('session.liveness_score', null)
            ->assertJsonPath('session.face_match_score', null)
            ->assertJsonPath('session.completed_at', null);

        $session->refresh();
        $this->assertSame('submitted', $session->status);
        $this->assertSame('submitted', $session->checks['session']);
        $this->assertNull($session->liveness_score);
        $this->assertNull($session->face_match_score);
        $this->assertNull($session->completed_at);

        $this->withToken($token)
            ->postJson($this->completeUrl($user, $session), [])
            ->assertOk()
            ->assertJsonPath('session.status', 'submitted');

        $this->withToken($token)
            ->post($this->uploadUrl($user, $session), [
                'capture_type' => 'selfie_liveness',
                'file' => UploadedFile::fake()->createWithContent('replacement.png', 'replacement'),
            ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Submitted identity evidence cannot be changed. Start a new verification session if another capture is required.',
            );
    }

    public function test_submission_requires_liveness_evidence(): void
    {
        $user = User::factory()->create();
        $session = $this->sessionFor($user, 'created');

        $this->withToken($this->issueTokenFor($user))
            ->postJson($this->completeUrl($user, $session), [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Selfie liveness evidence must be uploaded before submission.');

        $this->assertSame('created', $session->fresh()->status);
    }

    public function test_session_ownership_and_authentication_remain_enforced(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $session = $this->sessionFor($owner);

        $this->postJson($this->completeUrl($owner, $session), [])->assertUnauthorized();

        $this->withToken($this->issueTokenFor($otherUser))
            ->postJson($this->completeUrl($otherUser, $session), [])
            ->assertNotFound();
    }

    private function sessionFor(User $user, string $status = 'in_progress'): IdentityVerificationSession
    {
        return IdentityVerificationSession::query()->create([
            'user_id' => $user->id,
            'provider' => 'origin_capture',
            'external_session_id' => 'ivs_'.str()->random(32),
            'subject_type' => 'applicant',
            'status' => $status,
            'checks' => [],
            'raw_response' => ['mode' => 'origin_capture', 'captures' => []],
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = 'identity-trust-'.str()->random(40);

        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'identity trust boundary test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHour(),
        ]);

        return $plainToken;
    }

    private function uploadUrl(User $user, IdentityVerificationSession $session): string
    {
        return "/api/user/users/{$user->id}/identity-verification-sessions/{$session->id}/uploads";
    }

    private function completeUrl(User $user, IdentityVerificationSession $session): string
    {
        return "/api/user/users/{$user->id}/identity-verification-sessions/{$session->id}/complete";
    }
}
