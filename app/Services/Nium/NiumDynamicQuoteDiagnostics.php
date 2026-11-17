<?php

declare(strict_types=1);

namespace App\Services\Nium;

use App\Services\Wallet\Concurrency\OwnerLockContractViolation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Per HTTP Quote invocation. Services only mark stages; the controller reports once. */
final class NiumDynamicQuoteDiagnostics
{
    private const STAGES = ['eligibility', 'quote_preparation', 'wallet_transport', 'wallet_response_validation',
        'wallet_snapshot_persistence', 'quote_transport', 'quote_response_validation',
        'wallet_snapshot_validation', 'funds_validation', 'quote_persistence', 'quote_projection'];

    private const CODES = [
        'dynamic_quote_contract_disabled', 'dynamic_beneficiary_or_customer_ineligible',
        'dynamic_corridor_unsupported', 'dynamic_same_currency_quote_contract_unverified',
        'dynamic_provider_account_ineligible', 'dynamic_usd_wallet_unavailable',
        'dynamic_provider_quote_disabled', 'dynamic_quote_local_eligibility_changed',
        'dynamic_financial_scope_required', 'dynamic_pricing_corridor_unsupported', 'dynamic_financial_terms_changed',
        'dynamic_provider_environment_unverified', 'dynamic_provider_scope_unverified',
        'dynamic_quote_endpoint_unverified', 'dynamic_quote_registration_evidence_unavailable',
        'dynamic_registered_account_payload_changed', 'dynamic_registered_account_customer_scope_unavailable',
        'dynamic_eligibility_scope_changed', 'dynamic_customer_corridor_not_permitted',
        'dynamic_customer_corridor_ambiguous', 'dynamic_customer_corridor_response_contract_unavailable',
        'dynamic_customer_corridor_listing_unavailable', 'dynamic_eligibility_network_requires_no_transaction',
        'dynamic_quote_request_precision_unavailable', 'dynamic_quote_requires_network_outside_transaction',
        'dynamic_wallet_refresh_requires_network_outside_transaction', 'dynamic_wallet_authenticated_credentials_unavailable',
        'dynamic_wallet_snapshot_binding_changed', 'dynamic_wallet_authenticated_snapshot_unavailable',
        'dynamic_wallet_response_scope_unverified', 'dynamic_wallet_response_contract_unavailable',
        'dynamic_wallet_currency_unverified', 'dynamic_wallet_usd_availability_unavailable',
        'dynamic_wallet_available_balance_conflict', 'dynamic_wallet_has_unresolved_payout',
        'dynamic_execution_state_not_persisted', 'dynamic_amount_not_exactly_representable_in_wallet_ledger',
        'payout_quote_request_invalid', 'financial_decimal_invalid',
        'dynamic_provider_response_too_large', 'dynamic_provider_response_invalid', 'dynamic_provider_duplicate_field',
        'dynamic_quote_transport_binding_changed', 'dynamic_quote_binding_changed', 'dynamic_provider_quote_unavailable',
        'payout_quote_response_too_large', 'payout_quote_response_invalid', 'payout_quote_duplicate_field',
        'payout_quote_response_binding_mismatch', 'payout_quote_identity_or_expiry_invalid',
        'payout_quote_expiry_invalid', 'payout_quote_amounts_unavailable', 'payout_quote_amount_invalid',
        'payout_quote_input_mismatch', 'dynamic_quote_expired', 'dynamic_quote_amount_precision_unavailable',
        'dynamic_quote_already_consumed', 'dynamic_quote_wallet_balance_unavailable',
        'dynamic_authoritative_wallet_balance_unavailable', 'dynamic_insufficient_available_funds',
    ];

    private string $stage = 'eligibility';
    private bool $reported = false;

    public function stage(string $stage): void
    {
        $this->stage = in_array($stage, self::STAGES, true) ? $stage : 'unknown';
    }

    public function report(Throwable $exception): void
    {
        $class = match (true) {
            $exception instanceof QueryException => QueryException::class,
            $exception instanceof ModelNotFoundException => ModelNotFoundException::class,
            $exception instanceof ConnectionException => ConnectionException::class,
            $exception instanceof RequestException => RequestException::class,
            $exception instanceof NiumEvidencePersistenceException => NiumEvidencePersistenceException::class,
            $exception instanceof NiumProviderRequestException => NiumProviderRequestException::class,
            $exception instanceof OwnerLockContractViolation => OwnerLockContractViolation::class,
            $exception instanceof \JsonException => \JsonException::class,
            $exception instanceof \PDOException => \PDOException::class,
            $exception instanceof \InvalidArgumentException => \InvalidArgumentException::class,
            $exception instanceof \RuntimeException => \RuntimeException::class,
            $exception instanceof \TypeError => \TypeError::class,
            default => Throwable::class,
        };
        $code = match ($class) {
            QueryException::class => 'database_query_failed',
            ModelNotFoundException::class => 'model_not_found',
            ConnectionException::class => 'provider_transport_failed',
            RequestException::class, NiumProviderRequestException::class => 'provider_http_failed',
            NiumEvidencePersistenceException::class => 'provider_evidence_persistence_failed',
            OwnerLockContractViolation::class => 'owner_lock_contract_violation',
            \JsonException::class => 'invalid_json',
            \PDOException::class => 'database_operation_failed',
            default => self::code($exception->getMessage()),
        };
        $this->write($class, $code);
    }

    /** Expected preflight refusals have no thrown exception. */
    public function refusal(string $code): void
    {
        $this->write(null, self::code($code));
    }

    private static function code(string $message): string
    {
        foreach (self::CODES as $code) {
            if ($message === $code) return $code;
        }

        return 'unexpected_exception';
    }

    private function write(?string $class, string $code): void
    {
        if ($this->reported) return;
        $this->reported = true;
        try {
            Log::warning('nium.dynamic_quote.failed', [
                'stage' => $this->stage, 'exception_class' => $class, 'internal_code' => $code,
            ]);
        } catch (Throwable) {
            // Best effort only. Never replace the original failure or retry logging.
        }
    }
}
