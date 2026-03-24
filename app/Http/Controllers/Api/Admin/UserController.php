<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserIntegrationLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            $this->manageableUsersQuery()
                ->paginate(15)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'required_without:password_hash'],
            'password_hash' => ['nullable', 'string', 'min:6', 'required_without:password'],
            'status' => ['nullable', 'string', 'max:30'],
            'kyc_status' => ['nullable', 'string', 'max:30'],
            'integration_links' => ['sometimes', 'array'],
            'integration_links.*.provider_code' => ['required', 'string', 'distinct', 'exists:integration_providers,code'],
            'integration_links.*.link_url' => ['required', 'url', 'max:2048'],
            'integration_links.*.link_label' => ['nullable', 'string', 'max:100'],
            'integration_links.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $payload = collect($validated)
            ->except(['password', 'integration_links'])
            ->all();
        $payload['password_hash'] = $validated['password'] ?? $validated['password_hash'];

        $user = DB::transaction(function () use ($payload, $validated): User {
            $user = User::create($payload);
            $this->syncIntegrationLinks($user, $validated['integration_links'] ?? null);

            return $user->fresh(['profile', 'roles', 'integrationLinks.provider']);
        });

        return response()->json($this->userDetailPayload($user), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(
            $this->userDetailPayload(
                $this->resolveManageableUser($user)->load(['profile', 'roles', 'integrationLinks.provider'])
            )
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $user = $this->resolveManageableUser($user);

        $validated = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
            'password_hash' => ['sometimes', 'nullable', 'string', 'min:6'],
            'status' => ['sometimes', 'string', 'max:30'],
            'kyc_status' => ['sometimes', 'string', 'max:30'],
            'integration_links' => ['sometimes', 'array'],
            'integration_links.*.provider_code' => ['required', 'string', 'distinct', 'exists:integration_providers,code'],
            'integration_links.*.link_url' => ['required', 'url', 'max:2048'],
            'integration_links.*.link_label' => ['nullable', 'string', 'max:100'],
            'integration_links.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $user = DB::transaction(function () use ($user, $validated): User {
            $payload = collect($validated)
                ->except(['password', 'integration_links'])
                ->all();

            if (array_key_exists('password', $validated) || array_key_exists('password_hash', $validated)) {
                $payload['password_hash'] = $validated['password'] ?? $validated['password_hash'];
            }

            $user->update($payload);
            $this->syncIntegrationLinks(
                $user,
                array_key_exists('integration_links', $validated) ? $validated['integration_links'] : null
            );

            return $user->fresh(['profile', 'roles', 'integrationLinks.provider']);
        });

        return response()->json($this->userDetailPayload($user));
    }

    public function destroy(User $user): JsonResponse
    {
        $user = $this->resolveManageableUser($user);

        DB::transaction(fn () => $user->delete());

        return response()->json(status: 204);
    }

    private function manageableUsersQuery(): Builder
    {
        return User::query()
            ->with(['profile', 'roles', 'integrationLinks.provider'])
            ->nonAdmin()
            ->latest('id');
    }

    private function resolveManageableUser(User $user): User
    {
        $user->loadMissing('roles');

        abort_if($user->isAdmin(), 404);

        return $user;
    }

    private function userDetailPayload(User $user): array
    {
        return [
            ...$user->toArray(),
            'available_providers' => IntegrationProvider::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'status'])
                ->toArray(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $integrationLinks
     */
    private function syncIntegrationLinks(User $user, ?array $integrationLinks): void
    {
        if ($integrationLinks === null) {
            return;
        }

        $providerIdsByCode = IntegrationProvider::query()
            ->whereIn('code', collect($integrationLinks)->pluck('provider_code')->all())
            ->pluck('id', 'code');

        $providerIds = [];

        foreach ($integrationLinks as $integrationLink) {
            $providerId = $providerIdsByCode[$integrationLink['provider_code']];
            $providerIds[] = $providerId;

            UserIntegrationLink::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider_id' => $providerId,
                ],
                [
                    'link_url' => $integrationLink['link_url'],
                    'link_label' => $integrationLink['link_label'] ?? null,
                    'is_active' => $integrationLink['is_active'] ?? true,
                ]
            );
        }

        $user->integrationLinks()
            ->whereNotIn('provider_id', $providerIds)
            ->delete();
    }
}
