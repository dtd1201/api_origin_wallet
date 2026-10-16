<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumCorporateRfiAuthoritativeSubmissionService;
use App\Services\Nium\NiumCorporateRfiService;
use App\Services\Nium\NiumRfiWorkflowService;
use App\Services\Nium\NiumTransactionRfiSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

final class NiumRfiCaseController extends Controller
{
    private const LIVE_CONFIRMATION = 'SUBMIT_TO_NIUM_LIVE';

    public function __construct(
        private readonly NiumRfiWorkflowService $workflow,
        private readonly NiumTransactionRfiSubmissionService $transactionSubmission,
        private readonly NiumCorporateRfiAuthoritativeSubmissionService $authoritativeCorporateSubmission,
        private readonly NiumCorporateRfiService $corporateRfi,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['customer', 'transaction'])],
            'status' => ['nullable', 'string', 'max:30'],
            'submission_state' => ['nullable', Rule::in(['new', 'sent_to_user', 'user_draft', 'user_submitted', 'admin_review', 'revision_requested', 'admin_approved', 'nium_submission_pending', 'nium_submitted', 'nium_submission_failed', 'not_claimed', 'draft', 'approved', 'claimed', 'provider_acknowledged', 'unknown', 'rejected', 'responded', 'reconciled'])],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $cases = NiumRfiCase::query()
            ->with([
                'account:id,user_id',
                'account.user:id',
                'account.user.kycProfile:id,user_id,applicant_type,legal_name,business_name',
            ])
            ->when(isset($validated['scope']), fn ($query) => $query->where('scope', $validated['scope']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['submission_state']), fn ($query) => $query->where('submission_state', $validated['submission_state']))
            ->when(isset($validated['user_id']), fn ($query) => $query->whereHas('account', fn ($account) => $account->where('user_id', $validated['user_id'])))
            ->when($search !== '', function ($query) use ($search): void {
                $pattern = "%{$search}%";
                $query->where(function ($searchQuery) use ($search, $pattern): void {
                    if (preg_match('/^#?([1-9][0-9]*)$/', $search, $caseId) === 1) {
                        $searchQuery->orWhere('nium_rfi_cases.id', (int) $caseId[1]);
                    }

                    $searchQuery
                        ->orWhereHas('account.user.kycProfile', fn ($profile) => $profile
                            ->whereLike('legal_name', $pattern)
                            ->orWhereLike('business_name', $pattern))
                        ->orWhereLike('evidence->template->name', $pattern)
                        ->orWhereLike('evidence->template->documentType', $pattern);
                });
            })
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 15));

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

    public function submit(Request $request, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $this->assertNiumCase($niumRfiCase);
        if ($niumRfiCase->scope === 'customer') {
            $validated = $request->validate([
                'case_id' => ['required', 'integer', Rule::in([$niumRfiCase->id])],
                'confirmation' => ['required', Rule::in([self::LIVE_CONFIRMATION])],
                'provider_reference_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
                'template_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            ]);
            $case = $niumRfiCase->fresh();
            $this->assertSubmissionFingerprints($case, $validated);
            try {
                $from = (string) $case->submission_state;
                $case = $this->authoritativeCorporateSubmission->submit($case);
                $this->workflow->recordOperatorEvent($case, (int) $request->user()->id, 'authoritative_provider_submission_attempted', $from, (string) $case->submission_state);
            } catch (RuntimeException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        } else {
            $case = $this->transactionSubmission->submit($niumRfiCase);
        }

        return response()->json($this->workflow->detailPayload($case));
    }

    public function refreshStatus(Request $request, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $this->assertNiumCase($niumRfiCase);
        abort_unless($niumRfiCase->scope === 'customer' && $niumRfiCase->origin === 'authoritative_fetch', 404);
        $validated = $request->validate([
            'case_id' => ['required', 'integer', Rule::in([$niumRfiCase->id])],
            'provider_reference_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'template_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $case = $niumRfiCase->fresh();
        $this->assertSubmissionFingerprints($case, $validated);
        $account = UserProviderAccount::query()->with('user')->whereKey($case->user_provider_account_id)
            ->where('provider_id', $case->provider_id)->firstOrFail();
        $providerRfis = $this->corporateRfi->fetch($account);
        $matched = collect($providerRfis)->first(fn (array $rfi): bool => hash_equals(
            (string) $case->provider_reference_fingerprint,
            hash('sha256', (string) $rfi['rfiHashId']),
        ));
        if (! is_array($matched) || ! hash_equals($validated['template_fingerprint'], hash('sha256', (string) $matched['templateId']))) {
            return response()->json(['message' => 'The authoritative Nium RFI identity did not match the selected case.'], 422);
        }
        $from = (string) $case->submission_state;
        $this->workflow->reconcileCustomerEvidence($account->fresh(), $providerRfis);
        $case = $case->fresh();
        $this->workflow->recordOperatorEvent($case, (int) $request->user()->id, 'authoritative_provider_status_refreshed', $from, (string) $case->submission_state);

        return response()->json($this->workflow->detailPayload($case->fresh()));
    }

    public function sendToUser(Request $request, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $case = $this->workflow->sendToUser($niumRfiCase, (int) $request->user()->id);
        return response()->json($this->workflow->detailPayload($case));
    }

    public function requestRevision(Request $request, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $case = $this->workflow->requestRevision($niumRfiCase, (int) $request->user()->id, $validated['reason']);
        return response()->json($this->workflow->detailPayload($case));
    }

    public function approveUserSubmission(Request $request, NiumRfiCase $niumRfiCase): JsonResponse
    {
        $case = $this->workflow->approveUserSubmission($niumRfiCase, (int) $request->user()->id);
        return response()->json($this->workflow->detailPayload($case));
    }

    private function assertNiumCase(NiumRfiCase $case): void
    {
        if (! $case->user_provider_account_id || ! $case->provider_id
            || ! IntegrationProvider::query()->whereKey($case->provider_id)->whereRaw('LOWER(code) = ?', ['nium'])->exists()
            || ! UserProviderAccount::query()->whereKey($case->user_provider_account_id)->where('provider_id', $case->provider_id)->exists()) {
            abort(404);
        }
    }

    private function assertSubmissionFingerprints(NiumRfiCase $case, array $validated): void
    {
        $templateId = data_get($case->evidence, 'templateId');
        if (! hash_equals((string) $case->provider_reference_fingerprint, $validated['provider_reference_fingerprint'])
            || ! is_string($templateId)
            || ! hash_equals(hash('sha256', $templateId), $validated['template_fingerprint'])) {
            abort(422, 'The selected Corporate RFI fingerprint is stale or invalid.');
        }
    }
}
