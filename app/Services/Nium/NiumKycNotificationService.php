<?php

namespace App\Services\Nium;

use App\Jobs\DispatchKycNotificationEventJob;
use App\Models\KycNotificationEvent;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NiumKycNotificationService
{
    public function recordStateTransition(UserProviderAccount $account, array $before, array $after): void
    {
        $eventType = null;
        if (($before['status'] ?? null) !== 'active' && ($after['status'] ?? null) === 'active') {
            $eventType = 'approved';
        } elseif (($before['status'] ?? null) !== 'rejected'
            && ($after['status'] ?? null) === 'rejected'
            && ($after['provider_status'] ?? null) === 'rejected') {
            $eventType = 'rejected';
        }

        if ($eventType !== null) {
            $transitionVersion = $account->updated_at?->format('Y-m-d H:i:s.u') ?? 'unknown';
            $this->record($account, $eventType, "account:{$account->id}:{$eventType}:{$transitionVersion}", true);
        }
    }

    public function recordConfirmedRfi(UserProviderAccount $account, NiumRfiCase $case): void
    {
        if ($case->status !== 'requested') {
            return;
        }

        // Origin Wallet currently exposes the corporate RFI response workflow only to admins.
        $this->record($account, 'rfi', "rfi:{$case->id}:requested", false);
    }

    public function recordRfiSentToUser(UserProviderAccount $account, NiumRfiCase $case): void
    {
        $this->record($account, 'rfi', "rfi:{$case->id}:sent-to-user", true);
    }

    private function record(UserProviderAccount $account, string $eventType, string $identity, bool $includeUser): void
    {
        $account->loadMissing(['user.profile', 'user.kycProfile']);
        $user = $account->user;
        $idempotencyKey = hash('sha256', $identity);

        try {
            $event = KycNotificationEvent::query()->create([
                'user_provider_account_id' => $account->id,
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'payload' => [
                    'display_name' => $user->profile?->company_name
                        ?? $user->kycProfile?->business_name
                        ?? $user->kycProfile?->legal_name
                        ?? $user->full_name
                        ?? 'Customer',
                    'account_reference' => 'OW-'.$account->id,
                    'verified_status' => collect([$account->provider_status, $account->provider_sub_status])
                        ->filter(fn ($value) => filled($value))->implode(' / '),
                    'admin_review_url' => $this->adminReviewUrl($user),
                ],
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return;
            }
            throw $exception;
        }

        foreach ($this->recipients($user, $includeUser) as $recipient) {
            $event->deliveries()->create($recipient);
        }

        DB::afterCommit(function () use ($event): void {
            DispatchKycNotificationEventJob::dispatch($event->id);
            KycNotificationEvent::query()->whereKey($event->id)->update(['dispatched_at' => now()]);
        });
    }

    private function recipients(User $customer, bool $includeUser): array
    {
        $recipients = [];
        $admins = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query
                ->whereRaw('LOWER(role_code) = ?', ['super_admin'])
                ->whereHas('role', fn ($role) => $role->whereRaw('LOWER(code) = ?', ['super_admin'])))
            ->pluck('email');

        foreach ($admins as $email) {
            $this->addRecipient($recipients, (string) $email, 'admin');
        }
        $this->addRecipient($recipients, (string) config('services.kyc_notifications.extra_recipient'), 'admin');
        if ($includeUser) {
            $this->addRecipient($recipients, (string) $customer->email, 'user');
        }

        return array_values($recipients);
    }

    private function addRecipient(array &$recipients, string $email, string $type): void
    {
        $normalized = Str::lower(trim($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        if (! isset($recipients[$normalized])) {
            $recipients[$normalized] = [
                'recipient_type' => $type,
                'email' => trim($email),
                'normalized_email' => $normalized,
            ];
        }
    }

    private function adminReviewUrl(User $user): ?string
    {
        $base = trim((string) config('services.kyc_notifications.admin_review_url'));
        if ($base === '') {
            return null;
        }

        [$url, $fragment] = array_pad(explode('#', $base, 2), 2, null);
        $separator = str_contains($url, '?')
            ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&')
            : '?';

        return $url.$separator.'user_id='.$user->id.($fragment === null ? '' : '#'.$fragment);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
