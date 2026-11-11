<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\HttpReadiness;

use App\Domain\Nium\DynamicMultiCurrency\Options\CustomerAccountScope;
use App\Domain\Nium\DynamicMultiCurrency\Options\TransferOptionCandidate;
use App\Domain\Nium\DynamicMultiCurrency\Options\TransferOptionsService;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * Unregistered, pure request-boundary design. Accepts only SERVER-OWNED snapshots
 * and an explicit instant. No storage, middleware, request authentication context
 * or provider access. The token/profile projections are independently knowable;
 * a complete trusted production corridor snapshot source is still missing.
 */
final class ReadOnlyOptionsEvaluator
{
    public function evaluate(
        #[SensitiveParameter] ?string $bearerToken,
        ?LocalTokenSnapshot $token,
        string $routeUserId,
        ?ProfileAccessSnapshot $profile,
        ?CustomerAccountScope $scope,
        DateTimeImmutable $asOf,
        TransferOptionCandidate ...$candidates,
    ): ReadOnlyOptionsResponse {
        $actor = (new ReadOnlyCredentialPolicy)->authenticatedUserId($bearerToken, $token, $asOf);
        if ($actor === null) {
            return new ReadOnlyOptionsResponse(401, ['message' => 'Unauthenticated.']);
        }
        // Exact canonical IDs: never coerce "101other", "0101" or a float to 101.
        if ($actor !== $routeUserId || ($profile !== null && $profile->userId !== $actor)) {
            return new ReadOnlyOptionsResponse(403, ['message' => 'You are not allowed to access this user resource.']);
        }
        if ($profile === null || ! $profile->complete()) {
            return new ReadOnlyOptionsResponse(409, [
                'message' => 'User profile must be completed before accessing this resource.',
                'code' => 'PROFILE_REQUIRED',
            ]);
        }
        // No synthetic loader, fallback identity, global catalogue or discovery.
        if ($scope === null || ! $scope->authorizes($actor, $routeUserId, $asOf)) {
            return $this->unavailable();
        }
        $body = (new TransferOptionsService)->options($actor, $routeUserId, $scope, $asOf, ...$candidates)->toArray();
        if ($body['options'] === []) {
            return $this->unavailable();
        }

        return new ReadOnlyOptionsResponse(200, $body);
    }

    private function unavailable(): ReadOnlyOptionsResponse
    {
        return new ReadOnlyOptionsResponse(200, ['options' => [], 'reason_codes' => ['trusted_snapshot_unavailable']]);
    }
}
