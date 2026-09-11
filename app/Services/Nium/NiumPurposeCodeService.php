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
            'nium:purpose-codes',
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

                $result = [];
                foreach (is_array($items) ? $items : [] as $item) {
                    if (! is_array($item) || ! is_string($item['purposeCode'] ?? null) || ! is_string($item['description'] ?? null)) {
                        continue;
                    }
                    $code = trim($item['purposeCode']);
                    $label = trim($item['description']);
                    if ($code === '' || $label === '' || mb_strlen($code) > 100 || mb_strlen($label) > 255 || preg_match('/[\\p{C}]/u', $code.$label) === 1 || isset($result[$code])) {
                        continue;
                    }
                    $result[$code] = ['code' => $code, 'label' => $label];
                }

                return array_values($result);
            },
        );
    }
}
