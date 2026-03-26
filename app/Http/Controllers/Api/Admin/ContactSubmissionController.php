<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use Illuminate\Http\JsonResponse;

class ContactSubmissionController extends Controller
{
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
}
