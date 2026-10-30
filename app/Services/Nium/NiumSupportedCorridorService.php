<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class NiumSupportedCorridorService
{
    public function __construct(private readonly NiumService $niumService) {}

    /** Discover only the bank payout methods already supported by the wizard. */
    public function beneficiaryPayoutMethods(Beneficiary $beneficiary): array
    {
        $methods = [];
        foreach (['LOCAL', 'SWIFT'] as $method) {
            try {
                $this->assertSupported($beneficiary, $method, []);
                $methods[] = $method;
            } catch (NiumUnsupportedBeneficiaryCorridor) {
                // A successful lookup without an exact match does not admit this method.
            }
        }
        if ($methods === []) {
            throw new NiumUnsupportedBeneficiaryCorridor('No supported bank payout method exists for these beneficiary details.');
        }

        return $methods;
    }

    public function assertSupported(Beneficiary $beneficiary, string $payoutMethod, array $routingTypes): array
    {
        $accountType = in_array(strtolower((string) $beneficiary->beneficiary_type), ['company', 'business', 'corporate'], true)
            ? 'CORPORATE'
            : 'INDIVIDUAL';
        // Payout policy uses the KYC applicant type. Prefer the same authoritative
        // record here; older beneficiary-only integrations can lack a KYC profile.
        $customer = $beneficiary->user;
        $customerTypeValue = $customer?->kycProfile?->applicant_type
            ?? $customer?->profile?->user_type;
        $customerType = in_array(strtolower((string) $customerTypeValue), ['company', 'business', 'corporate'], true)
            ? 'CORPORATE'
            : 'INDIVIDUAL';
        $criteria = [
            'destinationCountry' => strtoupper($beneficiary->country_code),
            'destinationCurrency' => strtoupper($beneficiary->currency),
            'payoutMethod' => strtoupper($payoutMethod),
            'beneficiaryAccountType' => $accountType,
            'customerType' => $customerType,
            'page' => 0,
            'size' => 500,
            'order' => 'ASC',
        ];
        $corridors = (array) config('services.nium.supported_corridors', []);

        if ($corridors === []) {
            $endpoint = (string) config('services.nium.supported_corridors_endpoint', '');
            if ($endpoint === '') {
                throw new RuntimeException('Nium supported-corridor lookup is not configured.');
            }

            $cacheKey = 'nium:supported-corridor:v4:'.hash('sha256', json_encode([
                $criteria,
                $this->niumService->clientId(),
                config('services.nium.base_url'),
                $endpoint,
            ]));
            $corridors = Cache::remember($cacheKey, (int) config('services.nium.supported_corridors_cache_seconds', 3600), function () use ($endpoint, $criteria, $beneficiary): array {
                $response = $this->niumService->get(
                    path: $this->niumService->path($endpoint, ['client' => $this->niumService->clientId()]),
                    query: $criteria,
                    user: $beneficiary->user,
                );

                if (! $response->successful() || ! is_array($response->json())) {
                    throw new RuntimeException('Nium supported-corridor lookup failed.');
                }

                $data = $response->json();

                return array_is_list($data) ? $data : (array) ($data['content'] ?? []);
            });
        }

        unset($criteria['page'], $criteria['size'], $criteria['order']);

        $match = collect($corridors)->first(function ($corridor) use ($criteria): bool {
            if (! is_array($corridor)) {
                return false;
            }

            foreach ($criteria as $key => $value) {
                if (strtoupper((string) Arr::get($corridor, $key)) !== $value) {
                    return false;
                }
            }

            return true;
        });

        if (! is_array($match)) {
            throw new NiumUnsupportedBeneficiaryCorridor('The Nium beneficiary corridor, payout method, or account type is not supported.');
        }

        $allowedRouting = array_values(array_filter(array_map('strtoupper', (array) ($match['routingCodeType'] ?? []))));
        if (is_string($match['routingCodeType'] ?? null)) {
            $allowedRouting = [strtoupper($match['routingCodeType'])];
        }

        if ($routingTypes !== [] && $allowedRouting === []) {
            throw new RuntimeException('Nium corridor is missing authoritative routing types.');
        }

        foreach ($routingTypes as $routingType) {
            if ($allowedRouting !== [] && ! in_array(strtoupper($routingType), $allowedRouting, true)) {
                throw new RuntimeException('The beneficiary routing type is not supported for this Nium corridor.');
            }
        }

        return $match;
    }
}
