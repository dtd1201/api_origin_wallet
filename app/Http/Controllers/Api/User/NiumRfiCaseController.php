<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Services\Nium\NiumRfiWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class NiumRfiCaseController extends Controller
{
    public function __construct(private readonly NiumRfiWorkflowService $workflow) {}

    public function index(User $user): JsonResponse
    {
        $cases = NiumRfiCase::query()->where('scope', 'customer')->where('origin', 'authoritative_fetch')
            ->whereHas('account', fn ($query) => $query->where('user_id', $user->id))
            ->whereIn('submission_state', ['sent_to_user', 'user_draft', 'user_submitted', 'admin_review', 'revision_requested', 'admin_approved'])
            ->latest('id')->get();

        return response()->json(['data' => $cases->map(fn (NiumRfiCase $case) => $this->workflow->detailPayload($case))]);
    }

    public function upload(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240']]);
        $profile = $user->kycProfile;
        abort_unless($profile !== null, 422, 'A KYC/KYB profile is required before uploading RFI evidence.');
        $file = $validated['file'];
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = $file->guessExtension() ?: 'bin';
        $disk = 'kyc_private';
        $path = sprintf('kyc/%d/rfi/%s-%s.%s', $user->id, $hash, Str::uuid(), $extension);
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));
        $document = KycDocument::query()->create([
            'kyc_profile_id' => $profile->id,
            'type' => 'corporate_rfi_supporting_document',
            'status' => 'pending_admin_review',
            'file_url' => route('kyc-documents.show', ['user' => $user, 'artifactHash' => $hash]),
            'storage_disk' => $disk,
            'file_path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_hash' => $hash,
            'metadata' => ['factual' => true, 'synthetic' => false, 'rfi_upload' => true, 'uploaded_at' => now()->toISOString()],
        ]);

        return response()->json(['document' => ['id' => $document->id, 'original_name' => $document->original_name, 'mime_type' => $document->mime_type, 'file_size' => $document->file_size]], 201);
    }

    public function draft(Request $request, User $user, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $validated = $request->validate(['document_ids' => ['present', 'array', 'max:10'], 'document_ids.*' => ['integer'], 'explanation' => ['nullable', 'string', 'max:5000']]);
        $case = $this->workflow->saveUserDraft($niumRfiCase, (int) $user->id, $validated['document_ids'], $validated['explanation'] ?? null);

        return response()->json($this->workflow->detailPayload($case));
    }

    public function submit(User $user, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $case = $this->workflow->submitByUser($niumRfiCase, (int) $user->id);

        return response()->json($this->workflow->detailPayload($case));
    }
}
