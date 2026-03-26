<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactSubmissionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $submission = ContactSubmission::query()->create([
            ...$validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Contact message submitted successfully.',
            'data' => [
                'id' => $submission->id,
                'submitted_at' => $submission->submitted_at?->toISOString(),
            ],
        ], 201);
    }
}
