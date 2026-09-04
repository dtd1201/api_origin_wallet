<?php

namespace App\Services\Nium;

use App\Models\NiumCorporateConstant;
use App\Models\User;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

final class NiumCorporateConstantsService
{
    private const FALLBACK_SUBDIVISIONS = [
        'HK' => [
            ['label' => 'Central and Western', 'value' => 'HK-HCW'],
            ['label' => 'Eastern', 'value' => 'HK-HEA'],
            ['label' => 'Southern', 'value' => 'HK-HSO'],
            ['label' => 'Wan Chai', 'value' => 'HK-HWC'],
        ],
        'SG' => [
            ['label' => 'Central Singapore', 'value' => 'SG-01'],
            ['label' => 'North East', 'value' => 'SG-02'],
            ['label' => 'North West', 'value' => 'SG-03'],
            ['label' => 'South East', 'value' => 'SG-04'],
            ['label' => 'South West', 'value' => 'SG-05'],
        ],
        'VN' => [
            ['label' => 'Hanoi', 'value' => 'VN-HN'],
            ['label' => 'Ho Chi Minh City', 'value' => 'VN-SG'],
            ['label' => 'Phu Yen', 'value' => 'VN-70'],
        ],
    ];

    public function __construct(private readonly NiumService $niumService) {}

    /** @return array{values: list<array{label: string, value: string}>, source: string} */
    public function subdivisions(User $user, string $region, string $countryCode): array
    {
        $dimensions = [
            'region' => strtoupper(trim($region)),
            'customer_type' => 'CORPORATE',
            'country_code' => strtoupper(trim($countryCode)),
            'constant_type' => 'STATE',
        ];
        $cached = NiumCorporateConstant::query()->where($dimensions)->first();

        if ($cached !== null && $cached->expires_at->isFuture()) {
            return ['values' => (array) $cached->values, 'source' => 'cache'];
        }

        try {
            $values = $this->fetch($user, $dimensions);
            NiumCorporateConstant::query()->updateOrCreate($dimensions, [
                'values' => $values,
                'fetched_at' => now(),
                'expires_at' => now()->addSeconds((int) config('services.nium.corporate_constants_cache_seconds', 86400)),
            ]);

            return ['values' => $values, 'source' => 'nium'];
        } catch (Throwable $exception) {
            if ($cached !== null) {
                return ['values' => (array) $cached->values, 'source' => 'stale_cache'];
            }

            return [
                'values' => self::FALLBACK_SUBDIVISIONS[$dimensions['country_code']] ?? [],
                'source' => 'fallback',
            ];
        }
    }

    /** @return list<array{label: string, value: string}> */
    private function fetch(User $user, array $dimensions): array
    {
        $endpoint = (string) config('services.nium.corporate_constants_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('Nium corporate constants endpoint is not configured.');
        }

        $response = $this->niumService->get(
            path: $this->niumService->path($endpoint, ['client' => $this->niumService->clientId()]),
            query: [
                'region' => $dimensions['region'],
                'customerType' => $dimensions['customer_type'],
                'countryCode' => $dimensions['country_code'],
                'type' => $dimensions['constant_type'],
            ],
            user: $user,
            operation: 'fetch_corporate_constants',
        );

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Nium corporate constants lookup failed.');
        }

        $body = $response->json();
        $items = array_is_list($body)
            ? $body
            : (Arr::get($body, 'content') ?? Arr::get($body, 'data') ?? Arr::get($body, 'values') ?? []);

        if (! is_array($items)) {
            throw new RuntimeException('Nium corporate constants response is invalid.');
        }

        return collect($items)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): ?array {
                $value = $item['value'] ?? $item['code'] ?? $item['constantValue'] ?? null;
                $label = $item['label'] ?? $item['name'] ?? $item['description'] ?? $value;

                if (! is_string($value) || trim($value) === '' || ! is_string($label) || trim($label) === '') {
                    return null;
                }

                return ['label' => trim($label), 'value' => trim($value)];
            })
            ->filter()
            ->unique('value')
            ->values()
            ->all();
    }
}
