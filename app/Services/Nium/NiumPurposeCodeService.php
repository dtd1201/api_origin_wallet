<?php

namespace App\Services\Nium;

use App\Models\User;
use RuntimeException;

final class NiumPurposeCodeService
{
    public function __construct(private readonly NiumService $niumService) {}

    public function codes(User $user): array
    {
        $configured = (array) config('services.nium.purpose_codes', []);
        if ($configured !== []) {
            return array_values(array_filter(array_map(fn ($item) => is_array($item) ? ($item['purposeCode'] ?? null) : $item, $configured)));
        }

        $response = $this->niumService->get(
            path: (string) config('services.nium.purpose_codes_endpoint'),
            user: $user,
        );
        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            throw new RuntimeException('Nium purpose-code lookup failed.');
        }

        return array_values(array_filter(array_map(fn ($item) => is_array($item) ? ($item['purposeCode'] ?? null) : null, $data)));
    }

    public function assertValid(User $user, ?string $purposeCode): void
    {
        if (! filled($purposeCode) || ! in_array($purposeCode, $this->codes($user), true)) {
            throw new RuntimeException('Nium transfer requires an explicit factual purposeCode.');
        }
    }
}
