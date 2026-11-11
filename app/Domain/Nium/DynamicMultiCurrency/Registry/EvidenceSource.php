<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Registry;

/** Caller-supplied provenance classification, not proof of authenticity. */
enum EvidenceSource: string
{
    case Synthetic = 'SYNTHETIC';
    case ProviderResponse = 'PROVIDER_RESPONSE';
    case LocalAttestation = 'LOCAL_ATTESTATION';
}
