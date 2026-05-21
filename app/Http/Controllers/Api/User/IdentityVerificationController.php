<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerificationSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdentityVerificationController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'subject_type' => ['required', 'string', Rule::in([
                'applicant',
                'business',
                'authorized_representative',
                'beneficial_owner',
            ])],
        ]);

        $session = IdentityVerificationSession::query()
            ->where('user_id', $user->id)
            ->where('subject_type', $validated['subject_type'])
            ->whereIn('status', ['created', 'in_progress'])
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->first();

        if (! $session) {
            $session = IdentityVerificationSession::query()->create([
                'user_id' => $user->id,
                'kyc_profile_id' => $user->kycProfile?->id,
                'provider' => (string) config('services.identity_verification.provider', 'origin_capture'),
                'external_session_id' => 'ivs_'.Str::lower(Str::random(32)),
                'subject_type' => $validated['subject_type'],
                'status' => 'created',
                'started_at' => now(),
                'expires_at' => now()->addMinutes((int) config('services.identity_verification.session_ttl_minutes', 60)),
                'raw_response' => [
                    'mode' => 'origin_capture',
                    'captures' => [],
                ],
            ]);
        }

        return response()->json([
            'session' => $this->serializeSession($session),
            'required_captures' => $this->requiredCaptures($session->subject_type),
        ], 201);
    }

    public function upload(Request $request, User $user, IdentityVerificationSession $identityVerificationSession): JsonResponse
    {
        $this->assertSessionBelongsToUser($identityVerificationSession, $user);

        $validated = $request->validate([
            'capture_type' => ['required', 'string', Rule::in([
                'identity_front',
                'identity_back',
                'proof_of_address',
                'selfie_liveness',
                'business_registration',
                'proof_of_business_address',
                'ownership_structure',
            ])],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,mp4,mov', 'max:20480'],
            'metadata' => ['sometimes', 'array'],
        ]);

        if ($identityVerificationSession->expires_at && $identityVerificationSession->expires_at->isPast()) {
            abort(422, 'Identity verification session has expired.');
        }

        $file = $request->file('file');
        $disk = (string) config('services.identity_verification.evidence_disk', 'public');
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $fileHash = hash_file('sha256', $file->getRealPath());
        $path = sprintf(
            'kyc/%d/%d/%s-%s.%s',
            $user->id,
            $identityVerificationSession->id,
            $validated['capture_type'],
            Str::uuid(),
            $extension,
        );

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        $fileUrl = route('identity-verification.evidence.show', [
            'identityVerificationSession' => $identityVerificationSession,
            'artifactHash' => $fileHash,
        ]);
        $artifact = [
            'capture_type' => $validated['capture_type'],
            'file_url' => $fileUrl,
            'storage_disk' => $disk,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'file_hash' => $fileHash,
            'metadata' => $validated['metadata'] ?? [],
            'uploaded_at' => now()->toISOString(),
        ];

        $rawResponse = $identityVerificationSession->raw_response ?? [];
        $captures = $rawResponse['captures'] ?? [];
        $captures[] = $artifact;
        $rawResponse['captures'] = $captures;

        $checks = $identityVerificationSession->checks ?? [];
        $checks[$validated['capture_type']] = 'captured';

        $identityVerificationSession->update([
            'status' => 'in_progress',
            'checks' => $checks,
            'raw_response' => $rawResponse,
        ]);

        return response()->json([
            'session' => $this->serializeSession($identityVerificationSession->fresh()),
            'artifact' => $artifact,
        ]);
    }

    public function complete(Request $request, User $user, IdentityVerificationSession $identityVerificationSession): JsonResponse
    {
        $this->assertSessionBelongsToUser($identityVerificationSession, $user);

        $validated = $request->validate([
            'document_ocr' => ['sometimes', 'array'],
            'checks' => ['sometimes', 'array'],
            'liveness_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'face_match_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $identityVerificationSession->update([
            'status' => 'completed',
            'document_ocr' => $validated['document_ocr'] ?? $identityVerificationSession->document_ocr,
            'checks' => [
                ...($identityVerificationSession->checks ?? []),
                ...($validated['checks'] ?? []),
                'session' => 'completed',
            ],
            'liveness_score' => $validated['liveness_score'] ?? $identityVerificationSession->liveness_score,
            'face_match_score' => $validated['face_match_score'] ?? $identityVerificationSession->face_match_score,
            'completed_at' => now(),
        ]);

        return response()->json([
            'session' => $this->serializeSession($identityVerificationSession->fresh()),
        ]);
    }

    public function showEvidence(
        Request $request,
        IdentityVerificationSession $identityVerificationSession,
        string $artifactHash,
    ): StreamedResponse {
        $authenticatedUser = $request->user();

        if (! $authenticatedUser instanceof User) {
            abort(401);
        }

        $authenticatedUser->loadMissing('roles');

        if ($identityVerificationSession->user_id !== $authenticatedUser->id && ! $authenticatedUser->isAdmin()) {
            abort(403);
        }

        $artifact = $this->artifactByHash($identityVerificationSession, $artifactHash);

        if (! $artifact) {
            abort(404);
        }

        $disk = (string) ($artifact['storage_disk'] ?? config('services.identity_verification.evidence_disk', 'kyc_private'));
        $path = (string) ($artifact['file_path'] ?? '');

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        return Storage::disk($disk)->response(
            $path,
            (string) ($artifact['original_name'] ?? basename($path)),
            [
                'Content-Type' => (string) ($artifact['mime_type'] ?? 'application/octet-stream'),
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function assertSessionBelongsToUser(IdentityVerificationSession $session, User $user): void
    {
        if ($session->user_id !== $user->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(IdentityVerificationSession $session): array
    {
        return [
            'id' => $session->id,
            'provider' => $session->provider,
            'external_session_id' => $session->external_session_id,
            'subject_type' => $session->subject_type,
            'status' => $session->status,
            'liveness_score' => $session->liveness_score,
            'face_match_score' => $session->face_match_score,
            'document_ocr' => $session->document_ocr,
            'checks' => $session->checks,
            'expires_at' => $session->expires_at?->toISOString(),
            'completed_at' => $session->completed_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function artifactByHash(IdentityVerificationSession $session, string $artifactHash): ?array
    {
        foreach (($session->raw_response['captures'] ?? []) as $artifact) {
            if (($artifact['file_hash'] ?? null) === $artifactHash) {
                return $artifact;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function requiredCaptures(string $subjectType): array
    {
        if ($subjectType === 'business') {
            return ['business_registration', 'proof_of_business_address', 'ownership_structure'];
        }

        return ['identity_front', 'identity_back', 'proof_of_address', 'selfie_liveness'];
    }
}
