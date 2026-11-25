<?php

namespace App\Console\Commands;

use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\Auth\AuthChallengeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredAuthChallenges extends Command
{
    protected $signature = 'auth:prune-expired {--limit=500 : Maximum account batches}';

    protected $description = 'Erase expired pending authentication material without deleting replay or recovery history';

    public function handle(AuthChallengeService $challenges): int
    {
        $ids = AuthChallenge::where('expires_at', '<=', now())->whereNull('revoked_at')
            ->distinct()->limit(max(1, min(5000, (int) $this->option('limit'))))->pluck('user_id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $challenges): void {
                $user = User::whereKey($id)->lockForUpdate()->first();
                if ($user === null) {
                    return;
                }
                $challenges->lockFactor($user);
                AuthChallenge::where('user_id', $id)->where('expires_at', '<=', now())->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'pending_secret_ciphertext' => null, 'identity_proof_hash' => null]);
            });
        }
        $this->info('Expired pending authentication material erased.');

        return self::SUCCESS;
    }
}
