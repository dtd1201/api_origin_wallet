<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(User::with(['profile', 'roles'])->latest('id')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'password_hash' => ['required', 'string', 'min:6'],
            'status' => ['nullable', 'string', 'max:30'],
            'kyc_status' => ['nullable', 'string', 'max:30'],
        ]);

        $user = DB::transaction(fn () => User::create($validated));

        return response()->json($user, 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load(['profile', 'roles']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password_hash' => ['sometimes', 'string', 'min:6'],
            'status' => ['sometimes', 'string', 'max:30'],
            'kyc_status' => ['sometimes', 'string', 'max:30'],
        ]);

        $user = DB::transaction(function () use ($user, $validated): User {
            $user->update($validated);

            return $user->fresh();
        });

        return response()->json($user);
    }

    public function destroy(User $user): JsonResponse
    {
        DB::transaction(fn () => $user->delete());

        return response()->json(status: 204);
    }
}
