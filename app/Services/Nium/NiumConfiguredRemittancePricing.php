<?php

namespace App\Services\Nium;

use App\Contracts\NiumRemittancePricing;
use App\Models\ApiRequestLog;
use App\Models\Transfer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Verifiable client pricing inspection. Fee Details alone never confirms an all-in debit. */
final class NiumConfiguredRemittancePricing implements NiumRemittancePricing
{
    // get_nium_api is ProviderHttpClient's existing name for GETs without an explicit
    // operation. It is accepted only with the exact versioned endpoint and bindings.
    private const FEE_DETAILS_OPERATIONS = ['fee_details_v2', 'get_nium_api'];

    private const CUSTOMER_DETAILS_OPERATIONS = ['customer_details', 'get_nium_api'];

    public function __construct(private readonly NiumService $nium, private readonly NiumPayoutFxLockService $locks) {}

    public function refresh(Transfer $transfer): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('Pricing refresh requires committed state outside a transaction.');
        }
        $transfer->loadMissing(['provider', 'user']);
        $transfer->provider->assertSupportsCapability('transfer');
        $context = $this->locks->context($transfer->provider, $transfer->user);
        $this->nium->get($this->endpoint($context['client']), user: $transfer->user, operation: 'fee_details_v2');
    }

    public function quote(Transfer $transfer): array
    {
        try {
            $transfer->loadMissing(['provider', 'user']);
            $transfer->provider->assertSupportsCapability('transfer');
            $context = $this->locks->context($transfer->provider, $transfer->user);
            $path = $this->endpoint('[REDACTED]');
            $path = str_replace(urlencode('[REDACTED]'), '[REDACTED]', $path);
            $url = rtrim($context['base_url'], '/').$path;
            $log = ApiRequestLog::query()->with('niumExchangeEvidence')->where('provider_id', $transfer->provider_id)
                ->where('client_hash_id', $context['client'])->where('request_url', $url)
                ->whereIn('operation', self::FEE_DETAILS_OPERATIONS)->latest('id')->first();
            if ($log === null) {
                return ['status' => 'unavailable', 'reason' => 'configured_pricing_not_acquired'];
            }
            $evidence = $log->niumExchangeEvidence;
            $raw = $log->request_url === $url ? $this->verifiedRead($log) : null;
            if ($raw === null) {
                return ['status' => 'unavailable', 'reason' => 'configured_pricing_stale_or_unverifiable'];
            }
            $plans = $this->decode($raw);
            if (! is_array($plans) || ! array_is_list($plans) || $plans === []) {
                return ['status' => 'unavailable', 'reason' => 'configured_pricing_schema_unverified'];
            }
            foreach ($plans as $plan) {
                if (! is_array($plan) || ! is_array($plan['fees'] ?? null) || ! array_is_list($plan['fees']) || ! is_bool($plan['default'] ?? null)
                    || ! is_string($plan['segment'] ?? null) || ! is_string($plan['status'] ?? null)) {
                    return ['status' => 'unavailable', 'reason' => 'configured_pricing_schema_unverified'];
                }
            }

            $inspection = $this->inspectCustomerPricing($transfer, $context, $plans);

            // Matching configuration is not a charge-completeness or funding guarantee.
            // An absent REMIT_BANK_FEE row never authorizes a zero payout fee.
            return ['status' => 'unavailable', 'reason' => 'effective_customer_pricing_and_total_debit_unverified',
                'client_configuration_status' => 'confirmed', 'total_wallet_debit_status' => 'unverified',
                'additional_charges_status' => 'unverified',
                'configuration_reference' => ['api_request_log_id' => $log->id,
                    'operation' => $log->operation, 'response_sha256' => $evidence->response_body_sha256], 'pricing_inspection' => $inspection];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'reason' => 'configured_pricing_unverifiable'];
        }
    }

    private function inspectCustomerPricing(Transfer $transfer, array $context, array $plans): array
    {
        $active = array_values(array_filter($plans, fn (array $plan): bool => strtoupper($plan['status']) === 'ACTIVE'));
        $defaults = array_values(array_filter($active, fn (array $plan): bool => $plan['default'] === true));
        $result = ['customer_assignment' => 'unverified', 'effective_segment' => null,
            'payout_fee_status' => 'unverified', 'all_in_source_wallet_debit' => null,
            'blockers' => ['customer_assignment_unverified', 'complete_source_wallet_charge_allocation_unverified'],
            'fx_rate_semantics' => 'legacy_fx_rate_already_net_of_markup_never_apply_markup_again'];
        $urls = array_map(fn (int $version): string => rtrim($context['base_url'], '/')."/api/v{$version}/client/[REDACTED]/customer/[REDACTED]", [1, 2, 5]);
        $customerLog = ApiRequestLog::query()->with('niumExchangeEvidence')->where('provider_id', $transfer->provider_id)
            ->where('user_id', $transfer->user_id)->where('client_hash_id', $context['client'])
            ->whereIn('request_url', $urls)->whereIn('operation', self::CUSTOMER_DETAILS_OPERATIONS)->latest('id')->first();
        $customer = $customerLog === null ? null : $this->verifiedCustomer($customerLog, $context);
        $plan = null;
        if ($customer !== null && array_key_exists('segment', $customer)) {
            $result['customer_reference'] = ['api_request_log_id' => $customerLog->id,
                'operation' => $customerLog->operation, 'response_sha256' => $customerLog->niumExchangeEvidence->response_body_sha256];
            if ($customer['segment'] === null) {
                $result['customer_assignment'] = 'null_segment_default_fallback_unverified';
                $result['blockers'][0] = 'null_segment_default_fallback_unverified';
                // A single active default is a candidate, never proof of customer assignment.
                $plan = count($defaults) === 1 ? $defaults[0] : null;
            } elseif (is_string($customer['segment']) && trim($customer['segment']) !== '') {
                $matches = array_values(array_filter($active, fn (array $plan): bool => $plan['segment'] === $customer['segment']));
                if (count($matches) === 1) {
                    $plan = $matches[0];
                    $result['customer_assignment'] = 'explicit_segment';
                    $result['effective_segment'] = $plan['segment'];
                    array_shift($result['blockers']);
                } else {
                    $result['customer_assignment'] = 'explicit_segment_unmatched';
                    $result['blockers'][0] = 'customer_segment_missing_inactive_or_ambiguous';
                }
            }
        } elseif ($customerLog !== null) {
            $result['blockers'][0] = 'customer_capture_stale_unverifiable_or_segment_missing';
        }
        // Client configuration can still be inspected when the customer capture is absent.
        $plan ??= $result['customer_assignment'] === 'unverified' && count($defaults) === 1 ? $defaults[0] : null;
        if ($plan !== null) {
            $result['plan_candidate'] = ['segment' => $plan['segment'], 'default' => $plan['default'],
                'customer_assignment_verified' => $result['effective_segment'] !== null];
            try {
                $result['fx_rule_candidate'] = self::matchFxRule($plan['fees'], $transfer->source_currency, $transfer->target_currency,
                    (string) $transfer->source_amount);
                // V2 calls these slabs transaction-amount slabs, without defining the legacy payout
                // amount/currency basis. Comparison with sourceAmount is diagnostic until verified.
                $result['fx_rule_candidate']['slab_basis'] = 'source_principal_candidate_only_not_authorization';
                $result['blockers'][] = 'legacy_payout_slab_basis_and_fee_value_scale_unverified';
            } catch (RuntimeException $exception) {
                $result['fx_rule_candidate'] = null;
                $result['blockers'][] = $exception->getMessage();
            }
            $payoutRows = array_filter($plan['fees'], fn ($fee): bool => is_array($fee)
                && ($fee['feeName'] ?? null) === 'REMIT_BANK_FEE' && strtoupper((string) ($fee['status'] ?? '')) === 'ACTIVE');
            $result['payout_fee_status'] = $payoutRows === [] ? 'absent_row_does_not_establish_zero' : 'configured_rows_do_not_establish_complete_debit';
            $result['blockers'][] = $payoutRows === [] ? 'local_payout_charge_not_established_by_missing_row' : 'local_payout_all_in_debit_unverified';
        }

        return $result;
    }

    private function verifiedCustomer(ApiRequestLog $log, array $context): ?array
    {
        try {
            $raw = $this->verifiedRead($log);
            if ($raw === null) {
                return null;
            }
            $customer = $this->decode($raw);
            if (($customer['customerHashId'] ?? null) !== $context['customer']
                || (array_key_exists('clientHashId', $customer) && $customer['clientHashId'] !== $context['client'])
                || (array_key_exists('walletHashId', $customer) && $customer['walletHashId'] !== $context['wallet'])) {
                return null;
            }

            return $customer;
        } catch (Throwable) {
            return null;
        }
    }

    private function verifiedRead(ApiRequestLog $log): ?string
    {
        $evidence = $log->niumExchangeEvidence;
        $raw = $evidence?->raw_response_body;
        $time = $log->request_finished_at ?? $log->created_at;
        $age = (int) config('services.nium.fee_details_max_age_seconds', 300);
        $headers = $evidence?->request_headers ?? [];
        $uuid = $log->request_headers['x-request-id'] ?? null;
        if ($age < 1 || $age > 3600 || $time === null || $time->isFuture() || $time->lessThanOrEqualTo(now()->subSeconds($age))
            || ! $log->is_success || $log->response_status !== 200 || $log->request_method !== 'GET'
            || $evidence === null || $evidence->capture_version !== 1 || ! $evidence->response_received
            || $evidence->request_method !== 'GET' || $evidence->request_url !== $log->request_url
            || ! is_string($uuid) || ! Str::isUuid($uuid) || ($headers['x-request-id'] ?? null) !== [$uuid]
            || ! hash_equals((string) $evidence->request_headers_sha256,
                hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
            || ! is_string($raw) || $evidence->response_byte_length !== strlen($raw)
            || ! hash_equals((string) $evidence->response_body_sha256, hash('sha256', $raw))) {
            return null;
        }

        return $raw;
    }

    /** Exact-decimal rule selection for an explicitly supplied amount; never a debit calculation. */
    public static function matchFxRule(array $fees, string $authCurrency, string $transactionCurrency, string $amount): array
    {
        $amount = BigDecimal::of(NiumPayoutFxLockContract::decimal($amount));
        $groups = [];
        foreach ($fees as $index => $fee) {
            if (! is_array($fee) || ($fee['feeName'] ?? null) !== 'FX_MARKUP' || strtoupper((string) ($fee['status'] ?? '')) !== 'ACTIVE') {
                continue;
            }
            $auth = $fee['authCurrency'] ?? null;
            $transaction = $fee['transactionCurrency'] ?? null;
            if (! in_array($auth, [$authCurrency, 'ANY'], true) || ! in_array($transaction, [$transactionCurrency, 'ANY'], true)) {
                continue;
            }
            $specificity = (int) ($auth !== 'ANY') + (int) ($transaction !== 'ANY');
            $groups[$specificity][] = ['index' => $index, 'fee' => $fee];
        }
        // Inspect pair-specific rules first. An unused fallback cannot override or
        // invalidate an applicable explicit rule; ambiguity at that priority fails closed.
        krsort($groups);
        foreach ($groups as $specificity => $rules) {
            $matches = [];
            foreach ($rules as ['index' => $index, 'fee' => $fee]) {
                $lower = BigDecimal::of(self::decimal($fee['slabFrom'] ?? null));
                $upper = BigDecimal::of(self::decimal($fee['slabTo'] ?? null));
                if ($upper->isLessThan($lower)) {
                    throw new RuntimeException('fx_markup_slab_invalid');
                }
                if ($amount->isLessThan($lower) || $amount->isGreaterThan($upper)) {
                    continue;
                }
                if (($fee['fixed'] ?? null) !== false) {
                    throw new RuntimeException('fx_markup_percentage_contract_unverified');
                }
                $matches[] = ['row_index' => $index, 'specificity' => $specificity,
                    'auth_currency' => $fee['authCurrency'], 'transaction_currency' => $fee['transactionCurrency'], 'configured_value' => self::decimal($fee['feeValue'] ?? null),
                    'value_kind' => 'percentage', 'fee_value_percentage_scale' => 'requires_provider_confirmation',
                    'slab_from' => (string) $lower, 'slab_to' => (string) $upper,
                    'wallet_debit_amount' => null, 'fx_rate_treatment' => 'use_locked_effective_rate_unchanged'];
            }
            if ($matches === []) {
                continue;
            }
            if (count($matches) !== 1) {
                throw new RuntimeException('active_fx_markup_rule_ambiguous');
            }

            return $matches[0];
        }

        throw new RuntimeException('active_fx_markup_rule_missing');
    }

    private static function decimal(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException('pricing_decimal_unverifiable');
        }

        return NiumPayoutFxLockContract::decimal($value);
    }

    private function decode(string $raw): array
    {
        // Keep provider numbers exact, including fees and slab boundaries.
        $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
            static fn (array $match): string => '"'.$match[0].'"', $raw);
        $data = json_decode($quoted, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('pricing_schema_unverified');
        }

        return $data;
    }

    private function endpoint(string $client): string
    {
        $template = config('services.nium.fee_details_endpoint');
        // Only the saved versioned read contract is supported; no invented pricing endpoint.
        if ($template !== '/api/v2/client/{clientHashId}/fees') {
            throw new RuntimeException('Configured NIUM pricing API contract is unsupported.');
        }

        return $this->nium->path($template, ['client' => $client]);
    }
}
