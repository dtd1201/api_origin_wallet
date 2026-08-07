<?php

namespace App\Services\Nium;

use Throwable;

final class NiumHkFileStageExitCodes
{
    public const PASS = 0;

    public const HOLD = 20;

    public const PROVIDER_REJECTION = 30;

    public const UNKNOWN_OUTCOME = 40;

    public const PREFLIGHT_FAILURE = 50;

    public static function forStatus(string $status): int
    {
        return match ($status) {
            'PASS_FILE_AVAILABLE', 'PASS_DOCUMENT_AVAILABLE' => self::PASS,
            'HOLD_FILE_NOT_AVAILABLE' => self::HOLD,
            'HOLD_DETAILS_OUTCOME_UNKNOWN', 'HOLD_CREATE_OUTCOME_UNKNOWN' => self::UNKNOWN_OUTCOME,
            default => self::PREFLIGHT_FAILURE,
        };
    }

    public static function forException(Throwable $exception): int
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'was rejected') => self::PROVIDER_REJECTION,
            str_contains($message, 'outcome is unknown') => self::UNKNOWN_OUTCOME,
            str_contains($message, 'not AVAILABLE') => self::HOLD,
            default => self::PREFLIGHT_FAILURE,
        };
    }
}
