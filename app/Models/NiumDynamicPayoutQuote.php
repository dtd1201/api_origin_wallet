<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Isolated reference quotes: never legacy FxQuote records or admissible payout reviews. */
final class NiumDynamicPayoutQuote extends Model
{
    public const UPDATED_AT = null;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $dateFormat = 'Y-m-d H:i:s.uP';
    protected $guarded = ['*'];
    protected $hidden = ['request_json', 'response_json', 'binding'];

    protected function casts(): array
    {
        return ['binding' => 'array', 'response_json' => 'encrypted',
            'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Dynamic reference quotes are immutable.'));
        static::deleting(static fn () => throw new LogicException('Dynamic reference quotes cannot be deleted.'));
    }
}
