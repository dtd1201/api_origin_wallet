<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\NiumRfiCase;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumCorporateRfiAuthoritativeSubmissionOrchestrator
{
    public function __construct(
        private readonly NiumCorporateRfiPostEnvelopeAdapter $postEnvelopeAdapter,
        private readonly NiumService $niumService,
    ) {}

    /** Build a transport-shaped request plan without claiming the case or contacting Nium. */
    public function dryRun(NiumRfiCase $case): array
    {
        $provider = IntegrationProvider::query()->find($case->provider_id);
        if ($provider === null
            || strtolower(trim((string) $provider->code)) !== 'nium'
            || $provider->status !== 'active') {
            throw new RuntimeException('Authoritative Corporate RFI dry-run requires the active Nium provider.');
        }
        if ($case->contract_gate !== 'provider_contract_unconfirmed') {
            throw new RuntimeException('Authoritative Corporate RFI dry-run requires the provider contract to remain unconfirmed.');
        }
        if (strtoupper(trim((string) config('services.nium.customer_rfi_response_method'))) !== 'POST') {
            throw new RuntimeException('Authoritative Corporate RFI dry-run requires the reviewed POST method.');
        }

        $envelope = $this->postEnvelopeAdapter->prepare($case);
        if (($envelope['executable'] ?? null) !== false) {
            throw new RuntimeException('Authoritative Corporate RFI candidate envelopes must remain non-executable.');
        }

        $endpoint = $this->niumService->path(
            (string) data_get($envelope, 'transport.endpointTemplate'),
            (array) data_get($envelope, 'transport.pathParameters', []),
        );
        $requestId = (string) Str::uuid();
        $authHeaderName = strtolower(trim((string) data_get(config('services.nium.auth'), 'header_name')));
        if ($authHeaderName === '') {
            throw new RuntimeException('Authoritative Corporate RFI dry-run requires the configured Nium credential header.');
        }

        return [
            'executable' => false,
            'request' => [
                'method' => 'POST',
                'endpoint' => $endpoint,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'x-request-id' => $requestId,
                ],
                'credentialHeaderNames' => [$authHeaderName],
                'body' => $envelope['candidateBody'],
            ],
            'reviewMetadata' => $envelope['reviewMetadata'],
            'fieldEvidence' => $envelope['fieldEvidence'],
            'contractBlockers' => $envelope['contractBlockers'],
        ];
    }
}
