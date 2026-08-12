<?php

namespace App\Services\Nium;

use Illuminate\Support\Carbon;
use RuntimeException;

final readonly class NiumBeneficiaryRequirementsResult
{
    public function __construct(
        public array $dimensions,
        public array $routingCodeTypes,
        public array $fields,
        public string $schemaFingerprint,
        public string $fetchedAt,
        public string $cacheVersion,
        private string $integritySignature,
    ) {}

    public static function trusted(array $dimensions, array $routingCodeTypes, array $fields, string $fetchedAt, string $cacheVersion): self
    {
        $fingerprint = hash('sha256', json_encode([$dimensions, $routingCodeTypes, $fields], JSON_THROW_ON_ERROR));
        return new self($dimensions, $routingCodeTypes, $fields, $fingerprint, $fetchedAt, $cacheVersion,
            self::signature($dimensions, $routingCodeTypes, $fields, $fingerprint, $fetchedAt, $cacheVersion));
    }

    public function assertTrusted(int $maxAgeSeconds): void
    {
        $expected = self::signature($this->dimensions, $this->routingCodeTypes, $this->fields, $this->schemaFingerprint, $this->fetchedAt, $this->cacheVersion);
        if (! hash_equals($expected, $this->integritySignature)) {
            throw new RuntimeException('Nium beneficiary requirements schema provenance is invalid.');
        }
        if (Carbon::parse($this->fetchedAt)->lt(now()->subSeconds(max(1, $maxAgeSeconds)))) {
            throw new RuntimeException('Nium beneficiary requirements schema is stale.');
        }
    }

    private static function signature(array $dimensions, array $routingCodeTypes, array $fields, string $fingerprint, string $fetchedAt, string $cacheVersion): string
    {
        return hash_hmac('sha256', json_encode([$dimensions, $routingCodeTypes, $fields, $fingerprint, $fetchedAt, $cacheVersion], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
