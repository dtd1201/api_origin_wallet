<?php

namespace Tests\Concerns;

use App\Models\ApiRequestLog;
use App\Models\FxQuote;
use App\Models\NiumApiExchangeEvidence;
use App\Services\Nium\NiumPayoutQuoteContract;
use App\Services\Nium\NiumRejectedPayoutQuoteRecovery;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/** Synthetic incident recreation only. Never consumes a real audit or provider request. */
trait CreatesRejectedSgdQuoteIncident
{
    protected string $rejectedProviderArtifact = 'SYNTHETIC-PROVIDER-CLEARANCE-NOT-PRODUCTION';

    protected function rejectedIncident(): array
    {
        [$provider, $transfer, $operator] = $this->sgdExecutionFixture();
        $this->travel(2)->seconds(); // Distinguish earlier fixture events from the incident's audit window.
        $raw = $transfer->raw_data;
        $raw['sgd_local_workflow']['intent']['purpose_code'] = 'IR008';
        $raw['sgd_local_workflow']['state'] = 'lock_unknown';
        $raw['sgd_local_workflow']['lock_request_id'] = NiumRejectedPayoutQuoteRecovery::REQUEST;
        $raw['sgd_local_workflow']['lock_requested_at'] = now()->toISOString();
        $transfer->forceFill(['id' => 5, 'fx_quote_id' => null, 'fx_rate' => null,
            'purpose_code' => 'IR008', 'client_reference' => NiumRejectedPayoutQuoteRecovery::REFERENCE,
            'reference_text' => 'SYNTHETIC-INCIDENT', 'raw_data' => $raw])->save();
        FxQuote::query()->delete();
        ApiRequestLog::query()->where('operation', 'create_payout_fx_lock')->delete();
        $request = NiumPayoutQuoteContract::request('synthetic-customer', 'USD', 'SGD', '5');
        $request['executionType'] = 'manual';
        $request = json_encode($request, JSON_THROW_ON_ERROR);
        $response = json_encode(['errors' => [NiumRejectedPayoutQuoteRecovery::ERROR]], JSON_THROW_ON_ERROR);
        $log = ApiRequestLog::query()->forceCreate(['id' => 4763, 'provider_id' => 10, 'user_id' => 12,
            'operation' => 'create_payout_fx_lock', 'client_hash_id' => 'synthetic-client', 'request_method' => 'POST',
            'request_url' => 'https://nium.test/api/v1/client/[REDACTED]/quotes',
            'request_headers' => ['x-request-id' => NiumRejectedPayoutQuoteRecovery::REQUEST],
            'response_status' => 400, 'is_success' => false, 'transport_outcome' => 'response_received',
            'response_body' => ['response_received' => true], 'request_started_at' => now(), 'request_finished_at' => now()]);
        $evidence = NiumApiExchangeEvidence::query()->create(['api_request_log_id' => $log->id,
            'request_method' => 'POST', 'request_url' => $log->request_url, 'capture_version' => 1,
            'request_headers' => ['x-request-id' => [NiumRejectedPayoutQuoteRecovery::REQUEST]],
            'raw_request_body' => $request, 'raw_response_body' => $response,
            'request_body_sha256' => hash('sha256', $request), 'response_body_sha256' => hash('sha256', $response),
            'response_received' => true]);
        file_put_contents($this->sgdFenceDirectory.'/state', "CLOSED v1\n");
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        $clearance = ['transfer_id' => 5, 'audit_id' => 4763, 'request_uuid' => NiumRejectedPayoutQuoteRecovery::REQUEST,
            'reviewed_by' => $operator->id, 'request_body_sha256' => $evidence->request_body_sha256,
            'response_body_sha256' => $evidence->response_body_sha256,
            'provider_review' => ['reference' => 'SYNTHETIC-PROVIDER-REVIEW-NOT-REAL', 'artifact_sha256' => hash('sha256', $this->rejectedProviderArtifact),
                'no_quote_created' => true, 'no_conversion' => true, 'no_fx_hold' => true, 'no_debit_or_fee' => true]];

        return [$transfer->fresh(), $operator, $clearance, $log, $evidence];
    }
}
