<?php

namespace App\Services\Nium;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class NiumPurposeCodeService
{
    public function __construct(private readonly NiumService $nium) {}

    public function supported(User $user): array
    {
        return Cache::remember(
            'nium:purpose-codes:'.NiumTransferPolicy::PURPOSE_CODE,
            (int) config('services.nium.purpose_codes_cache_seconds', 3600),
            function () use ($user): array {
                $response = $this->nium->get(
                    path: $this->nium->path((string) config('services.nium.purpose_codes_endpoint')),
                    user: $user,
                    operation: 'purpose_codes_list',
                );

                if (! $response->successful()) {
                    throw new RuntimeException('Nium purpose codes are temporarily unavailable.');
                }

                $payload = (array) $response->json();
                $items = array_is_list($payload) ? $payload : ($payload['data'] ?? $payload['content'] ?? []);

                foreach (is_array($items) ? $items : [] as $item) {
                    if (! is_array($item) || ($item['purposeCode'] ?? null) !== NiumTransferPolicy::PURPOSE_CODE) {
                        continue;
                    }

                    $description = $item['description'] ?? null;
                    if (is_string($description)
                        && preg_match('/^[\pL\pN][\pL\pN .,&()\/-]{0,119}$/u', trim($description)) === 1) {
                        return [[
                            'code' => NiumTransferPolicy::PURPOSE_CODE,
                            'label' => trim($description),
                        ]];
                    }
                }

                return [];
            },
        );
    }
}
