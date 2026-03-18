<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderBeneficiaryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BeneficiaryController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            $user->beneficiaries()->latest('id')->get()
        );
    }

    public function show(User $user, Beneficiary $beneficiary): JsonResponse
    {
        abort_unless($beneficiary->user_id === $user->id, 404);

        return response()->json($beneficiary);
    }

    public function store(Request $request, User $user, ProviderBeneficiaryManager $manager): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'external_beneficiary_id' => ['nullable', 'string', 'max:255'],
            'beneficiary_type' => ['required', 'string', 'max:20'],
            'full_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
            'swift_bic' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $beneficiary = DB::transaction(function () use ($user, $validated, $manager): Beneficiary {
                $provider = IntegrationProvider::query()->findOrFail($validated['provider_id']);
                $beneficiary = $user->beneficiaries()->create([
                    ...$validated,
                    'status' => 'pending',
                ]);

                return $manager->createBeneficiary($provider, $beneficiary->load('user'));
            });
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($beneficiary, 201);
    }

    public function update(
        Request $request,
        User $user,
        Beneficiary $beneficiary,
        ProviderBeneficiaryManager $manager,
    ): JsonResponse
    {
        abort_unless($beneficiary->user_id === $user->id, 404);

        $validated = $request->validate([
            'beneficiary_type' => ['sometimes', 'string', 'max:20'],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:100'],
            'swift_bic' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        try {
            $beneficiary = DB::transaction(function () use ($beneficiary, $validated, $manager): Beneficiary {
                $beneficiary->update($validated);
                $provider = IntegrationProvider::query()->findOrFail($beneficiary->provider_id);

                return $manager->updateBeneficiary($provider, $beneficiary->fresh()->load('user'));
            });
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($beneficiary);
    }

    public function destroy(User $user, Beneficiary $beneficiary, ProviderBeneficiaryManager $manager): JsonResponse
    {
        abort_unless($beneficiary->user_id === $user->id, 404);

        try {
            DB::transaction(function () use ($beneficiary, $manager): void {
                $provider = IntegrationProvider::query()->findOrFail($beneficiary->provider_id);
                $manager->deleteBeneficiary($provider, $beneficiary->load('user'));
                $beneficiary->delete();
            });
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(status: 204);
    }
}
