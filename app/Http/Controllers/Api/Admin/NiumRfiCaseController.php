<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\NiumRfiCase;
use App\Services\Nium\NiumRfiWorkflowService;
use App\Services\Nium\NiumTransactionRfiSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NiumRfiCaseController extends Controller
{
    public function __construct(
        private readonly NiumRfiWorkflowService $workflow,
        private readonly NiumTransactionRfiSubmissionService $transactionSubmission,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['customer', 'transaction'])],
            'status' => ['nullable', 'string', 'max:30'],
            'submission_state' => ['nullable', Rule::in(['not_claimed', 'draft', 'approved', 'claimed', 'responded', 'reconciled'])],
        ]);
        $cases = NiumRfiCase::query()
            ->when(isset($validated['scope']), fn ($query) => $query->where('scope', $validated['scope']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['submission_state']), fn ($query) => $query->where('submission_state', $validated['submission_state']))
            ->latest('id')->paginate((int) $request->integer('per_page', 15));

        return response()->json($cases->through(fn (NiumRfiCase $case) => $this->workflow->listPayload($case)));
    }

    public function show(NiumRfiCase $niumRfiCase): JsonResponse
    {
        $this->assertNiumCase($niumRfiCase);
        return response()->json($this->workflow->detailPayload($niumRfiCase));
    }

    public function draft(Request $request, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.questionId' => ['required', 'string', 'max:255'],
            'answers.*.answer' => ['present'],
            'file_ids' => ['sometimes', 'array'],
            'file_ids.*' => ['string', 'max:255'],
        ]);
        $this->assertNiumCase($niumRfiCase);
        $case = $this->workflow->saveFactualDraft($niumRfiCase, $validated['answers'], $validated['file_ids'] ?? [], (int) $request->user()->id);
        return response()->json($this->workflow->detailPayload($case));
    }

    public function approve(Request $request, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $this->assertNiumCase($niumRfiCase);
        $case = $this->workflow->approve($niumRfiCase, (int) $request->user()->id);
        return response()->json($this->workflow->detailPayload($case));
    }

    public function submit(NiumRfiCase $niumRfiCase): JsonResponse
    {
        $this->assertNiumCase($niumRfiCase);
        $case = $this->transactionSubmission->submit($niumRfiCase);
        return response()->json($this->workflow->detailPayload($case));
    }

    private function assertNiumCase(NiumRfiCase $case): void
    {
        if (! $case->user_provider_account_id || ! $case->provider_id
            || ! \App\Models\IntegrationProvider::query()->whereKey($case->provider_id)->whereRaw('LOWER(code) = ?', ['nium'])->exists()
            || ! \App\Models\UserProviderAccount::query()->whereKey($case->user_provider_account_id)->where('provider_id', $case->provider_id)->exists()) {
            abort(404);
        }
    }
}
