<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\RecordsAdminAudit;
use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserIntegrationLink;
use App\Models\UserIntegrationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserIntegrationLinkController extends Controller
{
    use RecordsAdminAudit;

    public function index(User $user): JsonResponse
    {
        $user = $this->resolveManageableUser($user);
        $user->load(['integrationLinks.provider', 'integrationRequests.provider']);

        $providers = IntegrationProvider::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'user_id' => $user->id,
            'data' => $providers
                ->filter(fn (IntegrationProvider $provider) => $provider->supportsOnboarding())
                ->map(function (IntegrationProvider $provider) use ($user): array {
                    $link = $user->integrationLinks->firstWhere('provider_id', $provider->id);
                    $request = $user->integrationRequests->firstWhere('provider_id', $provider->id);

                    return [
                        'provider' => $provider->summaryPayload(),
                        'integration_link' => $link,
                        'integration_request' => $request,
                    ];
                })->values(),
        ]);
    }

    public function upsert(Request $request, User $user, IntegrationProvider $provider): JsonResponse
    {
        $user = $this->resolveManageableUser($user);

        $validated = $request->validate([
            'link_url' => ['required', 'url', 'max:2048'],
            'link_label' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! $provider->supportsOnboarding()) {
            return response()->json([
                'message' => 'This provider is not available for onboarding yet.',
            ], 422);
        }

        $before = [
            'integration_link' => UserIntegrationLink::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->first()?->toArray(),
            'integration_request' => UserIntegrationRequest::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->first()?->toArray(),
        ];

        try {
            $integrationLink = DB::transaction(function () use ($before, $provider, $request, $user, $validated): UserIntegrationLink {
                $integrationLink = UserIntegrationLink::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'provider_id' => $provider->id,
                    ],
                    [
                        'link_url' => $validated['link_url'],
                        'link_label' => $validated['link_label'] ?? null,
                        'is_active' => $validated['is_active'] ?? true,
                    ]
                );

                UserIntegrationRequest::query()
                    ->where('user_id', $user->id)
                    ->where('provider_id', $provider->id)
                    ->update([
                        'status' => 'resolved',
                        'resolved_at' => now(),
                    ]);

                $after = [
                    'integration_link' => $integrationLink->fresh()->toArray(),
                    'integration_request' => UserIntegrationRequest::query()
                        ->where('user_id', $user->id)
                        ->where('provider_id', $provider->id)
                        ->first()?->toArray(),
                ];

                $this->recordAdminAudit(
                    $request,
                    'integration_link.upserted',
                    'user_integration_link',
                    $integrationLink->id,
                    $before,
                    $after
                );

                return $integrationLink;
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "{$provider->name} integration link saved successfully.",
            'user_id' => $user->id,
            'provider' => $provider->summaryPayload(),
            'integration_link' => $integrationLink,
            'integration_request' => UserIntegrationRequest::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->first(),
        ]);
    }

    public function destroy(Request $request, User $user, IntegrationProvider $provider): JsonResponse
    {
        $user = $this->resolveManageableUser($user);

        if (! $provider->supportsOnboarding()) {
            return response()->json([
                'message' => 'This provider is not available for onboarding yet.',
            ], 422);
        }

        $before = [
            'integration_link' => UserIntegrationLink::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->first()?->toArray(),
            'target_user_id' => $user->id,
            'provider_id' => $provider->id,
        ];

        try {
            DB::transaction(function () use ($before, $provider, $request, $user): void {
                UserIntegrationLink::query()
                    ->where('user_id', $user->id)
                    ->where('provider_id', $provider->id)
                    ->delete();

                $this->recordAdminAudit(
                    $request,
                    'integration_link.deleted',
                    'user_integration_link',
                    $before['integration_link']['id'] ?? null,
                    $before,
                    null
                );
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(status: 204);
    }

    private function resolveManageableUser(User $user): User
    {
        $user->loadMissing('roles');

        abort_if($user->isAdmin(), 404);

        return $user;
    }
}
