<?php

namespace App\Services\Integrations;

use App\Models\Beneficiary;
use App\Models\BeneficiaryOperation;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Wallet\Concurrency\OwnerFirst;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Short U -> Provider KEY SHARE -> Beneficiary -> Operation roots; provider I/O stays outside. */
class BeneficiaryOperationService
{
    public function create(User $user, IntegrationProvider $provider, array $attributes, Closure $dispatch): Beneficiary
    {
        OwnerFirst::outside();
        // The public Create API has no client operation key. Each admitted request
        // is a new operation, even when its attributes match a completed Create.
        $key = (string) Str::uuid();
        $snapshot = OwnerFirst::users([$user->id], function () use ($user, $provider, $attributes, $key): Beneficiary {
            $this->lockProvider($provider->id);
            $this->assertNoUnresolvedCreate($user->id, $provider->id);
            $beneficiary = $user->beneficiaries()->create([
                ...$attributes, 'user_id' => $user->id, 'provider_id' => $provider->id, 'status' => 'pending',
            ]);
            return $this->claim($provider, $beneficiary, 'create', null, $key, true, $attributes);
        });

        return $this->dispatch($snapshot, $dispatch);
    }

    public function execute(IntegrationProvider $provider, Beneficiary $beneficiary, string $action,
        Closure $dispatch, ?Closure $prepare = null, ?array $request = null): ?Beneficiary
    {
        OwnerFirst::outside();
        if ($beneficiary->operationContext !== null) {
            $this->assertContext($provider, $beneficiary, $action);
            return $dispatch($beneficiary);
        }
        if ($beneficiary->getConnection() !== (new Beneficiary)->getConnection()) {
            throw new BeneficiaryOperationConflict;
        }
        if ($prepare === null) {
            $dirty = array_intersect_key($beneficiary->getDirty(), array_flip($beneficiary->getFillable()));
            $request ??= $action === 'create' ? $beneficiary->only($beneficiary->getFillable()) : $dirty;
            $prepare = fn (Beneficiary $snapshot) => $snapshot->fill($dirty);
        }
        $snapshot = OwnerFirst::users([$beneficiary->user_id], function () use ($provider, $beneficiary, $action, $prepare, $request): Beneficiary {
            $this->lockProvider($provider->id);
            $locked = Beneficiary::query()->whereKey($beneficiary->id)->lockForUpdate()->firstOrFail();
            if ((string) $locked->user_id !== (string) $beneficiary->user_id
                || (string) $locked->provider_id !== (string) $provider->id) throw new BeneficiaryOperationConflict;
            return $this->claim($provider, $locked, $action, $prepare, request: $request);
        });

        return $snapshot->operationContext === null ? $snapshot : $this->dispatch($snapshot, $dispatch);
    }

    private function claim(IntegrationProvider $provider, Beneficiary $locked, string $action,
        ?Closure $prepare = null, ?string $createKey = null, bool $new = false, ?array $request = null): Beneficiary
    {
        if (! in_array($action, ['create', 'update', 'delete'], true)
            || (string) $locked->provider_id !== (string) $provider->id
            || BeneficiaryOperation::query()->where('active_beneficiary_id', $locked->id)->exists()) {
            throw new BeneficiaryOperationConflict;
        }
        // A direct Create must never register an already registered beneficiary again.
        if ($action === 'create' && filled($locked->external_beneficiary_id)) throw new BeneficiaryOperationConflict;
        if ($action === 'create') $this->assertNoUnresolvedCreate($locked->user_id, $provider->id);
        $originalStatus = $locked->status;
        $candidate = clone $locked;
        if ($prepare !== null) $prepare($candidate);
        if ((string) $candidate->user_id !== (string) $locked->user_id
            || (string) $candidate->provider_id !== (string) $locked->provider_id
            || $candidate->external_beneficiary_id !== $locked->external_beneficiary_id) throw new BeneficiaryOperationConflict;
        $requestHash = self::fingerprint([$action, $request ?? $candidate->getDirty()]);
        $previous = BeneficiaryOperation::query()->where('beneficiary_id', $locked->id)->orderByDesc('generation')->first();
        if ($action === 'update' && $previous?->state === 'succeeded' && $previous->action === 'update'
            && $previous->request_hash === $requestHash && $previous->applied_hash === self::version($locked)) {
            return $locked;
        }
        if (! $locked->update(['status' => 'operation_pending'])) throw new RuntimeException('Beneficiary claim was not persisted.');
        $locked->refresh();
        $operation = BeneficiaryOperation::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => $locked->user_id, 'provider_id' => $provider->id,
            'beneficiary_id' => $locked->id, 'active_beneficiary_id' => $locked->id,
            'generation' => ($previous?->generation ?? 0) + 1,
            'create_key' => $createKey, 'action' => $action, 'state' => 'running',
            'request_id' => (string) Str::uuid(), 'verification_request_id' => (string) Str::uuid(),
            'baseline_hash' => self::version($locked),
            'request_hash' => $requestHash,
            'original_status' => $originalStatus, 'new_beneficiary' => $new,
        ]);
        $candidate->operationContext = $operation;
        return $candidate->load('user');
    }

    private function dispatch(Beneficiary $snapshot, Closure $dispatch): ?Beneficiary
    {
        $operation = $snapshot->operationContext;
        try {
            OwnerFirst::outside();
            $dispatch($snapshot);
            if ($operation->action !== 'delete' && (! is_scalar($snapshot->external_beneficiary_id)
                || trim((string) $snapshot->external_beneficiary_id) === '')) {
                throw new RuntimeException('Provider response did not contain a beneficiary identifier.');
            }
            if ($operation->action !== 'delete' && in_array(strtolower((string) $snapshot->status), ['failed', 'rejected', 'declined'], true)) {
                throw new RuntimeException('Provider did not accept the beneficiary operation.');
            }
            // Commit the accepted result before attempting the local business-state save.
            $this->withLocked($snapshot, function (Beneficiary $locked, BeneficiaryOperation $current) use ($snapshot): void {
                $this->saveOperation($current, ['state' => 'result_recorded',
                    'receipt' => ['attributes' => $this->resultAttributes($snapshot)]]);
            });
            return $this->applyRecorded($operation->id);
        } catch (Throwable $exception) {
            try {
                $this->withLocked($snapshot, function (Beneficiary $locked, BeneficiaryOperation $current) use ($exception, $snapshot): void {
                    // Every adapter authorizes each mutating HTTP step durably BEFORE
                    // entering transport. A crash after authorization is ambiguous,
                    // even when no response/evidence could be persisted.
                    $validationOnly = empty($current->dispatch_steps);
                    $this->saveOperation($current, ['state' => $validationOnly ? 'rejected' : 'ambiguous',
                        'failure_class' => $exception::class,
                        ...($validationOnly ? ['active_beneficiary_id' => null, 'create_key' => null] : [])]);
                    if ($validationOnly && $current->new_beneficiary) {
                        if (! $locked->delete()) throw new RuntimeException('Invalid beneficiary placeholder was not removed.');
                    } elseif (! $locked->update($validationOnly ? ['status' => $current->original_status] : [
                        // Retain the adapter's safe failure metadata, while the operation
                        // claim (not beneficiary status) remains the retry authority.
                        'status' => str_ends_with((string) $snapshot->status, '_failed') ? $snapshot->status : 'operation_unknown',
                        'raw_data' => $snapshot->raw_data,
                    ])) {
                        throw new RuntimeException('Beneficiary failure state was not persisted.');
                    }
                });
            } catch (Throwable) {
                // Never replace the primary failure. The committed running/result_recorded
                // claim still prevents redispatch, even when the database is unavailable.
            }
            throw $exception;
        }
    }

    /** Adapter result staging. No local beneficiary mutation occurs before final acceptance. */
    public static function write(Beneficiary $beneficiary, array $attributes): void
    {
        $service = app(self::class);
        if ($beneficiary->operationContext === null) throw new BeneficiaryOperationConflict;
        $candidate = clone $beneficiary;
        $candidate->fill($attributes);
        $service->withLocked($beneficiary, function (Beneficiary $locked, BeneficiaryOperation $operation) use ($service, $candidate): void {
            $service->saveOperation($operation, ['receipt' => ['attributes' => $service->resultAttributes($candidate)]]);
        });
        $beneficiary->fill($attributes);
    }

    public static function current(Beneficiary $beneficiary): Beneficiary
    {
        return $beneficiary->operationContext !== null ? $beneficiary : $beneficiary->fresh();
    }

    public static function beginMutation(Beneficiary $beneficiary, string $step): void
    {
        $service = app(self::class);
        $service->withLocked($beneficiary, function (Beneficiary $locked, BeneficiaryOperation $operation) use ($service, $step): void {
            $steps = $operation->dispatch_steps ?? [];
            if (isset($steps[$step])) throw new BeneficiaryOperationConflict;
            $steps[$step] = true;
            $service->saveOperation($operation, ['dispatch_steps' => $steps]);
        });
    }

    public static function requestId(Beneficiary $beneficiary, bool $verification = false): string
    {
        $operation = $beneficiary->operationContext;
        if ($operation === null) throw new BeneficiaryOperationConflict;
        return $verification ? $operation->verification_request_id : $operation->request_id;
    }

    /** Existing admin edits are local-only, but may not bypass a pending provider claim. */
    public function mutateLocally(Beneficiary $beneficiary, Closure $work, array $newOwners = [], array $newProviders = []): mixed
    {
        OwnerFirst::outside();
        return OwnerFirst::users([$beneficiary->user_id, ...$newOwners], function () use ($beneficiary, $work, $newProviders): mixed {
            $providers = array_unique([(int) $beneficiary->provider_id, ...array_map('intval', $newProviders)]);
            sort($providers, SORT_NUMERIC);
            foreach ($providers as $provider) $this->lockProvider($provider);
            $locked = Beneficiary::query()->whereKey($beneficiary->id)->lockForUpdate()->firstOrFail();
            if ((string) $locked->user_id !== (string) $beneficiary->user_id
                || (string) $locked->provider_id !== (string) $beneficiary->provider_id
                || BeneficiaryOperation::query()->where('active_beneficiary_id', $locked->id)->exists()) {
                throw new BeneficiaryOperationConflict;
            }
            return $work($locked);
        });
    }

    /** Local-only recovery of a durably accepted result; never repeats provider HTTP. No API route. */
    public function applyRecorded(string $operationId): ?Beneficiary
    {
        OwnerFirst::outside();
        $discovered = BeneficiaryOperation::query()->useWritePdo()->findOrFail($operationId);
        return OwnerFirst::users([$discovered->user_id], function () use ($discovered): ?Beneficiary {
            $this->lockProvider($discovered->provider_id);
            $locked = Beneficiary::query()->whereKey($discovered->beneficiary_id)->lockForUpdate()->firstOrFail();
            $operation = BeneficiaryOperation::query()->whereKey($discovered->id)->lockForUpdate()->firstOrFail();
            $this->assertBinding($locked, $operation);
            if ($operation->state !== 'result_recorded' || ! is_array($operation->receipt['attributes'] ?? null)) {
                throw new BeneficiaryOperationConflict;
            }
            if ($operation->action === 'delete') {
                if (! $locked->delete()) throw new RuntimeException('Beneficiary deletion was not persisted.');
            } elseif (! $locked->update($operation->receipt['attributes'])) {
                throw new RuntimeException('Beneficiary provider result was not persisted.');
            }
            $this->saveOperation($operation, ['state' => 'succeeded', 'active_beneficiary_id' => null,
                'applied_hash' => $operation->action === 'delete' ? null : self::version($locked->fresh())]);
            return $operation->action === 'delete' ? null : $locked->fresh();
        });
    }

    private function withLocked(Beneficiary $snapshot, Closure $work): mixed
    {
        OwnerFirst::outside();
        $context = $snapshot->operationContext;
        if ($context === null) throw new BeneficiaryOperationConflict;
        if ((string) $snapshot->user_id !== (string) $context->user_id
            || (string) $snapshot->provider_id !== (string) $context->provider_id) throw new BeneficiaryOperationConflict;
        return OwnerFirst::users([$context->user_id], function () use ($snapshot, $context, $work): mixed {
            $this->lockProvider($context->provider_id);
            $locked = Beneficiary::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $operation = BeneficiaryOperation::query()->whereKey($context->id)->lockForUpdate()->firstOrFail();
            $this->assertBinding($locked, $operation);
            if ($operation->state !== 'running') throw new BeneficiaryOperationConflict;
            return $work($locked, $operation);
        });
    }

    private function assertContext(IntegrationProvider $provider, Beneficiary $snapshot, string $action): void
    {
        $operation = BeneficiaryOperation::query()->useWritePdo()->findOrFail($snapshot->operationContext->id);
        if (($operation->action !== $action && ! ($operation->action === 'update' && $action === 'create'))
            || $operation->state !== 'running' || (string) $operation->beneficiary_id !== (string) $snapshot->id
            || (string) $operation->user_id !== (string) $snapshot->user_id
            || (string) $operation->provider_id !== (string) $provider->id
            || (string) $snapshot->provider_id !== (string) $provider->id) throw new BeneficiaryOperationConflict;
    }

    private function assertBinding(Beneficiary $locked, BeneficiaryOperation $operation): void
    {
        if ((string) $locked->user_id !== (string) $operation->user_id
            || (string) $locked->provider_id !== (string) $operation->provider_id
            || (string) $locked->id !== (string) $operation->active_beneficiary_id
            || self::version($locked) !== $operation->baseline_hash) {
            throw new BeneficiaryOperationConflict;
        }
    }

    private function resultAttributes(Beneficiary $snapshot): array
    {
        return array_diff_key($snapshot->only($snapshot->getFillable()), array_flip(['user_id', 'provider_id']));
    }

    private function saveOperation(BeneficiaryOperation $operation, array $attributes): void
    {
        if (! $operation->update($attributes)) throw new RuntimeException('Beneficiary operation evidence was not persisted.');
    }

    private function lockProvider(int $providerId): void
    {
        IntegrationProvider::query()->whereKey($providerId)->lock('for key share')->firstOrFail();
    }

    /** Called under the owner lock; payload equality never establishes retry identity. */
    private function assertNoUnresolvedCreate(int $userId, int $providerId): void
    {
        // Without a client key we cannot distinguish a retry from a new intent.
        // Fail closed across this owner/provider until the earlier Create is resolved,
        // including old STEP 280 claims whose create_key was a payload hash.
        if (BeneficiaryOperation::query()->where('user_id', $userId)->where('provider_id', $providerId)
            ->where(fn ($query) => $query->where('action', 'create')->orWhere('dispatch_steps->create', true))
            ->whereNotIn('state', ['succeeded', 'rejected'])->exists()) {
            throw new BeneficiaryOperationConflict;
        }
    }

    private static function version(Beneficiary $beneficiary): string
    {
        // Eloquent's API visibility must not exclude hidden routing/operational
        // fields from the stale-response fence. Normalize PostgreSQL JSON text.
        return self::fingerprint([...$beneficiary->getAttributes(), 'raw_data' => $beneficiary->raw_data]);
    }

    private static function fingerprint(array $input): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) ksort($value);
            foreach ($value as &$item) if (is_array($item)) $item = $sort($item);
            return $value;
        };
        return hash('sha256', json_encode($sort($input), JSON_THROW_ON_ERROR));
    }
}
