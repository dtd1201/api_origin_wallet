<?php

namespace App\Services\Nium;

use App\Models\NiumCorporateConstant;
use App\Models\User;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

final class NiumCorporateConstantsService
{
    public const CATEGORIES = [
        'annualTurnover', 'averageTransactionValue', 'businessType', 'countryName',
        'countryOfOperation', 'documentType', 'intendedUseOfAccount', 'industrySector',
        'monthlyTransactionVolume', 'monthlyTransactions', 'position', 'totalEmployees',
    ];

    public function __construct(private readonly NiumService $niumService) {}

    public function values(User $user, string $region, string $category, ?string $countryCode = null): array
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new RuntimeException('Unsupported Nium corporate constant category.');
        }
        $dimensions = [
            'region' => strtoupper(trim($region)),
            'customer_type' => 'CORPORATE',
            'country_code' => strtoupper(trim((string) $countryCode)),
            'constant_type' => $category,
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

            throw new RuntimeException('Nium corporate constants are unavailable and no cached values exist.', previous: $exception);
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
                'type' => 'CORPORATE',
                'category' => $dimensions['constant_type'],
                ...($dimensions['country_code'] !== '' ? ['countryCode' => $dimensions['country_code']] : []),
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
                $value = $item['code'] ?? null;
                $label = $item['description'] ?? null;

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
