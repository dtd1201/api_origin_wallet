<?php

namespace App\Services\Auth;

use App\Exceptions\AuthenticationServiceUnavailable;
use App\Mail\AuthSecurityNoticeMail;
use App\Models\User;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuthSecurity
{
    public function __construct(private readonly AuthVerificationSecurity $events) {}

    /**
     * Only the required issuer call is classified here. Rethrow through the
     * caller's transaction so rollback precedes HTTP exception rendering.
     */
    public function tokenIssuance(callable $issue): string
    {
        try {
            return $issue();
        } catch (RuntimeException|RandomException $exception) {
            // Preserve HTTP denials and missing-record behavior; LogicException
            // and PHP programming errors are not caught by this boundary.
            if ($exception instanceof HttpExceptionInterface || $exception instanceof RecordsNotFoundException) {
                throw $exception;
            }

            throw new AuthenticationServiceUnavailable;
        }
    }

    public function limit(string $purpose, string $account, ?string $challenge = null): void
    {
        [$accountLimit, $ipLimit] = config('totp.rates.'.$purpose, config('totp.rates.verification'));
        $dimensions = ['account:'.hash('sha256', strtolower(trim($account))) => $accountLimit,
            'ip:'.hash('sha256', request()->ip() ?? 'unknown') => $ipLimit];
        if ($challenge !== null) {
            $dimensions['challenge:'.hash('sha256', $challenge)] = 10;
        }
        try {
            foreach ($dimensions as $dimension => $limit) {
                $key = 'mandatory-totp:'.$purpose.':'.$dimension;
                // Check+hit must be atomic across workers. Required cache locks
                // failing never silently make authentication unlimited.
                Cache::lock($key.':mutex', 5)->block(2, function () use ($key, $limit): void {
                    if (RateLimiter::tooManyAttempts($key, max(1, (int) $limit))) {
                        abort(429, 'Too many authentication attempts.', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
                    }
                    RateLimiter::hit($key, 60);
                });
            }
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 429) {
                $this->event('authentication_rate_limited', null, ['purpose' => $purpose]);
            }
            throw $exception;
        } catch (Throwable) {
            abort(503, 'Authentication protection is unavailable.');
        }
    }

    public function event(string $type, ?User $user = null, array $metadata = []): void
    {
        $safe = array_intersect_key($metadata, array_flip(['purpose', 'audience', 'challenge_id',
            'factor_version', 'reason', 'attempts', 'actor_ids', 'grant_id', 'count']));
        $this->events->record($type, $user?->email ?? '', $user?->id, request()->ip(), null, $safe);
    }

    public function notice(User $user, string $event): void
    {
        $id = $user->id;
        DB::afterCommit(function () use ($id, $event): void {
            $user = User::find($id);
            if ($user === null) {
                return;
            }
            try {
                Mail::to($user->email)->queue(new AuthSecurityNoticeMail($event));
            } catch (Throwable) {
                $this->event('security_notice_delivery_failed', $user);
            }
        });
    }
}
