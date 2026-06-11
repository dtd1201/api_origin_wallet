<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\RecordsAdminAudit;
use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactSubmissionController extends Controller
{
    use RecordsAdminAudit;

    public function index(): JsonResponse
    {
        return response()->json(
            ContactSubmission::query()
                ->latest('submitted_at')
                ->latest('id')
                ->paginate(15)
        );
    }

    public function show(ContactSubmission $contactSubmission): JsonResponse
    {
        return response()->json($contactSubmission);
    }

    public function destroy(Request $request, ContactSubmission $contactSubmission): JsonResponse
    {
        $this->recordAdminAudit(
            $request,
            'contact_submission.deleted',
            'contact_submission',
            $contactSubmission->id,
            $contactSubmission->toArray(),
            null
        );

        $contactSubmission->delete();

        return response()->json(status: 204);
    }
}
