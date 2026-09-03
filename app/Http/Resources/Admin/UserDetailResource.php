<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class UserDetailResource extends UserListResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'integration_links' => $this->when(
                $request->user()?->hasPermission('users.manage') === true,
                fn () => $this->integrationLinks->map(fn ($link) => [
                    'id' => $link->id,
                    'user_id' => $link->user_id,
                    'provider_id' => $link->provider_id,
                    'link_url' => $link->link_url,
                    'link_label' => $link->link_label,
                    'is_active' => $link->is_active,
                    'provider' => $link->relationLoaded('provider') && $link->provider
                        ? (new ProviderSummaryResource($link->provider))->resolve($request)
                        : null,
                    'created_at' => $link->created_at,
                    'updated_at' => $link->updated_at,
                ])->values()
            ),
        ];
    }
}
