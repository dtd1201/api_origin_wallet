<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Intentionally unrelated to Transfer. Never enroll this model in legacy dispatch. */
final class NiumDynamicIntent extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    // timestamptz must receive an offset, independent of the PostgreSQL session timezone.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['confirmed_terms', 'consent_binding', 'admission_evidence'];

    /** Permanent local uniqueness scope, shared by confirmation and reference-review checks. */
    public static function quoteConsumptionKey(int $providerId, string $environment, string $client,
        string $customer, string $quoteId): string
    {
        return hash('sha256', json_encode([$providerId, $environment, $client, $customer, $quoteId], JSON_THROW_ON_ERROR));
    }

    protected function casts(): array
    {
        return [
            'confirmed_terms' => 'array', 'consent_binding' => 'array', 'admission_evidence' => 'array',
            'quote_expires_at' => 'immutable_datetime', 'consented_at' => 'immutable_datetime',
            'total_debit' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Confirmed dynamic intents are immutable.'));
        static::deleting(static fn () => throw new LogicException('Confirmed dynamic intents cannot be deleted.'));
    }
}
