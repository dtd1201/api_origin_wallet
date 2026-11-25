<?php

namespace App\Services\Auth;

use App\Models\AuthRecoveryCode;
use App\Models\UserAuthFactor;
use Illuminate\Support\Facades\Hash;

class RecoveryCodeService
{
    /** Caller holds account/factor/challenge locks. Raw codes leave only in response. */
    public function rotate(UserAuthFactor $factor): array
    {
        AuthRecoveryCode::where('factor_id', $factor->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $selector = bin2hex(random_bytes(8));
            $secret = bin2hex(random_bytes(16));
            $row = new AuthRecoveryCode;
            $row->forceFill(['factor_id' => $factor->id, 'set_version' => $factor->recovery_set_version,
                'selector' => $selector, 'code_hash' => Hash::make($secret)])->save();
            $codes[] = $selector.'-'.implode('-', str_split($secret, 8));
        }

        return $codes;
    }

    public function valid(UserAuthFactor $factor, #[\SensitiveParameter] string $code, bool $lock = false): ?AuthRecoveryCode
    {
        $parts = explode('-', strtolower(trim($code)), 2);
        if (count($parts) !== 2 || preg_match('/\A[0-9a-f]{16}\z/D', $parts[0]) !== 1) {
            return null;
        }
        $secret = str_replace('-', '', $parts[1]);
        if (preg_match('/\A[0-9a-f]{32}\z/D', $secret) !== 1) {
            return null;
        }
        $row = AuthRecoveryCode::where('factor_id', $factor->id)->where('set_version', $factor->recovery_set_version)
            ->where('selector', $parts[0])->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($row === null || $row->consumed_at !== null || ! Hash::check($secret, $row->code_hash)) {
            return null;
        }

        return $row;
    }

    public function consume(UserAuthFactor $factor, #[\SensitiveParameter] string $code): bool
    {
        $row = $this->valid($factor, $code, true);
        if ($row === null) {
            return false;
        }

        return AuthRecoveryCode::whereKey($row->id)->whereNull('consumed_at')->update(['consumed_at' => now()]) === 1;
    }
}
