<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\HttpReadiness\LocalTokenSnapshot;
use App\Domain\Nium\DynamicMultiCurrency\HttpReadiness\ProfileAccessSnapshot;
use App\Domain\Nium\DynamicMultiCurrency\HttpReadiness\ReadOnlyCredentialPolicy;
use App\Domain\Nium\DynamicMultiCurrency\HttpReadiness\ReadOnlyOptionsEvaluator;
use App\Domain\Nium\DynamicMultiCurrency\HttpReadiness\ReadOnlyOptionsResponse;
use App\Domain\Nium\DynamicMultiCurrency\Options\CustomerAccountScope;
use App\Domain\Nium\DynamicMultiCurrency\Options\TransferOptionCandidate;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceRecord;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use Closure;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure offline tests, NOT Laravel HTTP integration tests. Every fixture is synthetic. */
final class NiumDynamicHttpReadinessTest extends TestCase
{
    private const BEARER = 'private-synthetic-readiness-token';

    private Closure $autoloadGuard;

    private array $runtimeLoadAttempts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoloadGuard = function (string $class): void {
            if ((str_starts_with($class, 'App\\') && ! str_starts_with($class, 'App\\Domain\\Nium\\DynamicMultiCurrency\\'))
                || str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'GuzzleHttp\\')) {
                $this->runtimeLoadAttempts[] = $class;
                throw new LogicException('Readiness tests must not load application, HTTP, database or wallet runtime.');
            }
        };
        spl_autoload_register($this->autoloadGuard, true, true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloadGuard);
        try {
            $this->assertSame([], $this->runtimeLoadAttempts);
        } finally {
            parent::tearDown();
        }
    }

    private function time(string $value = '2026-11-09T00:30:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    private function token(array $changes = []): LocalTokenSnapshot
    {
        return new LocalTokenSnapshot(...array_replace([
            'storedHash' => hash('sha256', self::BEARER), 'tokenUserId' => '101', 'relatedUserId' => '101',
            'expiresAt' => $this->time('2026-11-09T01:00:00Z'),
        ], $changes));
    }

    private function profile(array $changes = []): ProfileAccessSnapshot
    {
        return new ProfileAccessSnapshot(...array_replace([
            'userId' => '101', 'kycStatus' => 'pending', 'profileExists' => true, 'userType' => 'business',
        ], $changes));
    }

    private function scope(array $changes = []): CustomerAccountScope
    {
        return new CustomerAccountScope(...array_replace([
            'ownerUserId' => '101', 'provider' => 'nium', 'environment' => 'sandbox', 'client' => 'private-client',
            'legalEntity' => 'private-entity', 'customer' => 'private-customer', 'account' => 'private-account',
            'customerType' => 'CORPORATE', 'profileVersion' => 'private-profile-v1',
            'fundingWallets' => ['EUR' => 'private-wallet'], 'ownership' => EvidenceState::Verified,
            'validFrom' => $this->time('2026-11-09T00:00:00Z'), 'expiresAt' => $this->time('2026-11-09T01:00:00Z'),
            'revocation' => RevocationStatus::NotRevoked,
        ], $changes));
    }

    private function corridor(array $changes = []): CorridorIdentity
    {
        return new CorridorIdentity(...array_replace([
            'provider' => 'nium', 'environment' => 'sandbox', 'client' => 'private-client', 'legalEntity' => 'private-entity',
            'customer' => 'private-customer', 'account' => 'private-account', 'fundingWallet' => 'private-wallet',
            'sourceCurrency' => 'EUR', 'destinationCountry' => 'DE', 'destinationCurrency' => 'EUR',
            'payoutMethod' => 'LOCAL', 'customerType' => 'CORPORATE', 'beneficiaryType' => 'INDIVIDUAL',
            'contractVersion' => 'private-contract-v1', 'profileVersion' => 'private-profile-v1',
        ], $changes));
    }

    private function evidence(CorridorIdentity $corridor, EvidenceKind $kind, EvidenceState $state = EvidenceState::Verified): EvidenceRecord
    {
        return new EvidenceRecord('private-id-'.$kind->value, $corridor, $kind, $state, EvidenceSource::Synthetic,
            'private-evidence-document', $this->time('2026-11-09T00:00:00Z'), $this->time('2026-11-09T00:00:00Z'),
            $this->time('2026-11-09T00:00:00Z'), $this->time('2026-11-09T01:00:00Z'), RevocationStatus::NotRevoked);
    }

    private function candidate(?CustomerAccountScope $scope = null, ?CorridorIdentity $corridor = null, ?array $records = null): TransferOptionCandidate
    {
        $scope ??= $this->scope();
        $corridor ??= $this->corridor();
        $records ??= array_map(fn (EvidenceKind $kind): EvidenceRecord => $this->evidence($corridor, $kind), EvidenceKind::required());

        return new TransferOptionCandidate($scope, $corridor, $records, null, null, null);
    }

    private function evaluate(array $changes = [], TransferOptionCandidate ...$candidates): ReadOnlyOptionsResponse
    {
        return (new ReadOnlyOptionsEvaluator)->evaluate(...array_values(array_replace([
            'bearerToken' => self::BEARER, 'token' => $this->token(), 'routeUserId' => '101',
            'profile' => $this->profile(), 'scope' => $this->scope(), 'asOf' => $this->time(),
        ], $changes)), ...$candidates);
    }

    private function assertNoStore(ReadOnlyOptionsResponse $response): void
    {
        $this->assertSame(['Cache-Control' => 'private, no-store', 'Content-Type' => 'application/json'], $response->headers());
    }

    private function assertClosed(ReadOnlyOptionsResponse $response): void
    {
        $this->assertSame(200, $response->status());
        foreach ($response->body()['options'] as $option) {
            $this->assertFalse($option['enabled']);
            $this->assertSame('DENIED', $option['preparation_eligibility']);
            $this->assertSame('DENIED', $option['dispatch_eligibility']);
            $this->assertContains('dynamic_dispatch_disabled', $option['reason_codes']);
        }
        $this->assertNoStore($response);
    }

    public static function invalidBearerTokens(): array
    {
        return [
            'missing' => [null], 'empty' => [''], 'wrong' => ['another-token'],
            'case changed' => [strtoupper(self::BEARER)], 'space prefixed' => [' '.self::BEARER],
            'space suffixed' => [self::BEARER.' '], 'not a hash passthrough' => [hash('sha256', self::BEARER)],
        ];
    }

    #[DataProvider('invalidBearerTokens')]
    public function test_invalid_credentials_are_generic_401_without_options(?string $bearer): void
    {
        $response = $this->evaluate(['bearerToken' => $bearer], $this->candidate());
        $this->assertSame(401, $response->status());
        $this->assertSame(['message' => 'Unauthenticated.'], $response->body());
        $this->assertNoStore($response);
    }

    public function test_missing_deleted_malformed_or_orphaned_token_is_rejected(): void
    {
        foreach ([null, $this->token(['storedHash' => 'malformed']), $this->token(['storedHash' => str_repeat('0', 64)]),
            $this->token(['tokenUserId' => null]), $this->token(['relatedUserId' => null]),
            $this->token(['relatedUserId' => '202']), $this->token(['tokenUserId' => '0101', 'relatedUserId' => '0101'])] as $token) {
            // NULL also represents a fresh lookup after logout/password-reset deletion.
            $this->assertNull((new ReadOnlyCredentialPolicy)->authenticatedUserId(self::BEARER, $token, $this->time()));
            $response = $this->evaluate(['token' => $token]);
            $this->assertSame(401, $response->status());
            $this->assertNoStore($response);
        }
    }

    public function test_expired_credentials_including_exact_expiry_boundary_are_rejected_without_deletion(): void
    {
        foreach (['2026-11-09T00:29:59Z', '2026-11-09T00:30:00Z', '2026-11-09T07:30:00+07:00'] as $expiresAt) {
            $token = $this->token(['expiresAt' => $this->time($expiresAt)]);
            $before = serialize($token);
            $response = $this->evaluate(['token' => $token]);
            $this->assertSame(401, $response->status());
            $this->assertSame(['message' => 'Unauthenticated.'], $response->body());
            $this->assertSame($before, serialize($token));
            $this->assertNoStore($response);
        }
    }

    public function test_valid_and_explicit_null_expiry_tokens_preserve_existing_credential_contract(): void
    {
        foreach ([$this->token(), $this->token(['expiresAt' => null])] as $token) {
            $this->assertSame('101', (new ReadOnlyCredentialPolicy)->authenticatedUserId(self::BEARER, $token, $this->time()));
            $this->assertClosed($this->evaluate(['token' => $token], $this->candidate()));
        }
    }

    public function test_authorization_uses_exact_user_ownership_without_numeric_coercion(): void
    {
        foreach (['202', '', '0101', '101suffix', '101.0', '101 ', ' 101'] as $routeUser) {
            $response = $this->evaluate(['routeUserId' => $routeUser], $this->candidate());
            $this->assertSame(403, $response->status());
            $this->assertSame(['message' => 'You are not allowed to access this user resource.'], $response->body());
            $this->assertNoStore($response);
        }
        $this->assertSame(403, $this->evaluate(['profile' => $this->profile(['userId' => '202'])])->status());
    }

    public function test_authentication_precedes_ownership_profile_and_snapshot_disclosure(): void
    {
        $response = $this->evaluate(['token' => null, 'routeUserId' => '202', 'profile' => null, 'scope' => null]);
        $this->assertSame(401, $response->status());
        $response = $this->evaluate(['routeUserId' => '202', 'profile' => null, 'scope' => null]);
        $this->assertSame(403, $response->status());
        $response = $this->evaluate(['profile' => null, 'scope' => null]);
        $this->assertSame(409, $response->status());
    }

    public function test_profile_required_matches_existing_local_profile_contract(): void
    {
        foreach ([null, $this->profile(['profileExists' => false]), $this->profile(['userType' => null]),
            $this->profile(['userType' => '']), $this->profile(['userType' => '  ']) ] as $profile) {
            $response = $this->evaluate(['profile' => $profile]);
            $this->assertSame(409, $response->status());
            $this->assertSame(['message' => 'User profile must be completed before accessing this resource.', 'code' => 'PROFILE_REQUIRED'], $response->body());
            $this->assertNoStore($response);
        }
        foreach (['approved', 'verified', 'APPROVED', 'VERIFIED'] as $kyc) {
            $this->assertClosed($this->evaluate(['profile' => $this->profile(['kycStatus' => $kyc, 'profileExists' => false, 'userType' => null])], $this->candidate()));
        }
        $this->assertClosed($this->evaluate(['profile' => $this->profile(['kycStatus' => 'pending'])], $this->candidate()));
    }

    public function test_missing_or_untrusted_source_returns_unavailable_without_a_catalogue(): void
    {
        $expected = ['options' => [], 'reason_codes' => ['trusted_snapshot_unavailable']];
        foreach ([null, $this->scope(['ownerUserId' => '202']), $this->scope(['ownership' => EvidenceState::Unknown]),
            $this->scope(['expiresAt' => $this->time()]), $this->scope(['revocation' => RevocationStatus::Revoked])] as $scope) {
            $response = $this->evaluate(['scope' => $scope], $this->candidate());
            $this->assertSame($expected, $response->body());
            $this->assertClosed($response);
        }
        $this->assertSame($expected, $this->evaluate()->body());
    }

    public static function foreignCorridors(): array
    {
        return [
            'customer' => [['customer' => 'foreign-customer']], 'account' => [['account' => 'foreign-account']],
            'environment' => [['environment' => 'production']], 'client' => [['client' => 'foreign-client']],
            'entity' => [['legalEntity' => 'foreign-entity']], 'wallet' => [['fundingWallet' => 'foreign-wallet']],
            'source currency' => [['sourceCurrency' => 'USD']], 'profile' => [['profileVersion' => 'foreign-profile']],
        ];
    }

    #[DataProvider('foreignCorridors')]
    public function test_customer_account_and_environment_isolation_is_retained(array $changes): void
    {
        $foreign = $this->candidate(corridor: $this->corridor($changes));
        $response = $this->evaluate([], $foreign);
        $this->assertSame(['options' => [], 'reason_codes' => ['trusted_snapshot_unavailable']], $response->body());
        $this->assertClosed($response);
        $own = $this->candidate();
        $this->assertSame($this->evaluate([], $own)->body(), $this->evaluate([], $foreign, $own)->body());
    }

    public function test_missing_trusted_evidence_and_missing_financial_terms_cannot_enable_options(): void
    {
        $response = $this->evaluate([], $this->candidate(records: []));
        $option = $response->body()['options'][0];
        $this->assertSame('UNKNOWN', $option['financial_readiness']);
        $this->assertSame('UNKNOWN', $option['beneficiary_compatibility']);
        $this->assertContains('registry_scope_not_found', $option['reason_codes']);
        $this->assertContains('financial_readiness_unverified', $option['reason_codes']);
        $this->assertClosed($response);
        $response = $this->evaluate([], $this->candidate());
        $this->assertSame('UNKNOWN', $response->body()['options'][0]['financial_readiness']);
        $this->assertClosed($response);
    }

    public function test_sgd_local_remains_on_hold_and_dispatch_stays_denied(): void
    {
        $corridor = $this->corridor(['sourceCurrency' => 'SGD', 'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD']);
        $scope = $this->scope(['fundingWallets' => ['SGD' => 'private-wallet']]);
        $response = $this->evaluate(['scope' => $scope], $this->candidate($scope, $corridor));
        $this->assertContains('sgd_local_hold', $response->body()['options'][0]['reason_codes']);
        $this->assertClosed($response);
    }

    public function test_supplied_hold_overrides_remain_authoritative(): void
    {
        $corridor = $this->corridor();
        $records = array_map(fn (EvidenceKind $kind): EvidenceRecord => $this->evidence($corridor, $kind), EvidenceKind::required());
        $records[] = $this->evidence($corridor, EvidenceKind::Override, EvidenceState::Hold);
        $response = $this->evaluate([], $this->candidate(records: $records));
        $this->assertContains('hold_override', $response->body()['options'][0]['reason_codes']);
        $this->assertClosed($response);
    }

    public function test_no_identifiers_tokens_hashes_or_provenance_escape_into_response(): void
    {
        foreach ([$this->evaluate([], $this->candidate()), $this->evaluate(['token' => null]),
            $this->evaluate(['scope' => null]), $this->evaluate(['routeUserId' => '202'])] as $response) {
            $serialized = json_encode([$response->status(), $response->body(), $response->headers()], JSON_THROW_ON_ERROR);
            foreach ([self::BEARER, hash('sha256', self::BEARER), 'private-', 'foreign-', '"101"', '"202"'] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
            $this->assertNoStore($response);
        }
    }

    public function test_evaluation_is_repeatable_immutable_and_has_no_runtime_dependencies(): void
    {
        $token = $this->token();
        $profile = $this->profile();
        $scope = $this->scope();
        $candidate = $this->candidate($scope);
        $inputs = [$token, $profile, $scope, $candidate];
        $before = serialize($inputs);
        $evaluator = new ReadOnlyOptionsEvaluator;
        $first = $evaluator->evaluate(self::BEARER, $token, '101', $profile, $scope, $this->time(), $candidate);
        $loaded = get_included_files();
        $second = $evaluator->evaluate(self::BEARER, $token, '101', $profile, $scope, $this->time(), $candidate);
        $after = get_included_files();
        $this->assertSame($first->body(), $second->body());
        $this->assertSame($before, serialize($inputs));
        $this->assertSame($loaded, $after);
        $this->assertSame([], $this->runtimeLoadAttempts);
    }
}
