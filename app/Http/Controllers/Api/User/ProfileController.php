<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderOnboardingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProfileController extends Controller
{
    public function show(User $user): JsonResponse
    {
        return response()->json(
            $user->load(['profile', 'roles'])
        );
    }

    public function update(Request $request, User $user, ProviderOnboardingManager $onboardingManager): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'provider_code' => ['sometimes', 'string', 'exists:integration_providers,code'],
            'profile.user_type' => ['sometimes', 'string', 'max:20'],
            'profile.country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'profile.company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.company_reg_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $selectedProvider = $this->resolveRequestedProvider($validated['provider_code'] ?? null);

        $user = DB::transaction(function () use ($user, $validated, $selectedProvider): User {
            $user->fill(collect($validated)->except(['profile', 'provider_code'])->all());

            $user->save();

            if (array_key_exists('profile', $validated)) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $validated['profile']
                );

                if ($selectedProvider !== null) {
                    $user->providerAccounts()->updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'provider_id' => $selectedProvider->id,
                            'external_account_id' => null,
                        ],
                        [
                            'status' => 'pending',
                            'metadata' => [
                                'integration_status' => 'awaiting_provider_details',
                                'selected_provider_code' => $selectedProvider->code,
                                'profile_submitted_at' => now()->toISOString(),
                            ],
                        ]
                    );
                }
            }
            return $user->fresh()->load(['profile', 'roles']);
        });

        $message = 'Profile updated successfully.';

        if ($selectedProvider !== null) {
            try {
                $onboardingManager->linkUser($selectedProvider, $user->fresh(['profile', 'providerAccounts.provider']), true);
                $message = "Profile updated and {$selectedProvider->name} onboarding request sent successfully.";
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
            }
        }

        return response()->json([
            'message' => $message,
            'user' => $user->fresh()->load(['profile', 'roles', 'providerAccounts.provider']),
        ]);
    }

    private function resolveRequestedProvider(?string $providerCode): ?IntegrationProvider
    {
        if ($providerCode === null || $providerCode === '') {
            return null;
        }

        return IntegrationProvider::query()
            ->where('code', $providerCode)
            ->first();
    }
}
