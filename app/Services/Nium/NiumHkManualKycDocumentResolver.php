<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\KycRelatedPerson;
use Illuminate\Support\Str;

final class NiumHkManualKycDocumentResolver
{
    public const FUTURE_FACTUAL_METADATA = [
        'factual' => true,
        'factual_evidence' => true,
        'synthetic' => false,
        'synthetic_only' => false,
        'synthetic_test' => false,
        'historical_only' => false,
        'superseded' => false,
    ];

    private const PROFILE_ID = 9;
    private const PERSON_ID = 14;
    private const HISTORICAL_SYNTHETIC_IDS = [18, 19, 20, 21, 22, 23];

    public function resolve(KycRelatedPerson $person): array
    {
        if ((int) $person->id !== self::PERSON_ID || (int) $person->kyc_profile_id !== self::PROFILE_ID) {
            return ['identity' => null, 'proof_of_address' => null];
        }

        $documents = KycDocument::query()
            ->where('kyc_profile_id', self::PROFILE_ID)
            ->where('kyc_related_person_id', self::PERSON_ID)
            ->get();

        return [
            'identity' => $this->eligible($documents, ['passport', 'passport_front', 'identity_document', 'identity_document_front', 'national_id_front']),
            'proof_of_address' => $this->eligible($documents, ['proof_of_address', 'utility_bill', 'bank_statement']),
        ];
    }

    private function eligible($documents, array $purposes): ?KycDocument
    {
        return $documents->first(function (KycDocument $document) use ($purposes): bool {
            $metadata = (array) $document->metadata;
            $storedType = strtolower(trim((string) $document->type));
            $purpose = strtolower(trim((string) ($metadata['document_purpose'] ?? $document->type)));
            $fileId = $metadata['nium_file_id'] ?? null;
            $factual = ($metadata['factual'] ?? null) === true
                || ($metadata['factual_evidence'] ?? null) === true;
            $synthetic = ($metadata['synthetic'] ?? null) === true
                || ($metadata['synthetic_only'] ?? null) === true
                || ($metadata['synthetic_test'] ?? null) === true;
            $historical = ($metadata['historical_only'] ?? null) === true;
            $supersededAt = $metadata['superseded_at'] ?? null;
            $superseded = $document->status === 'superseded'
                || ($metadata['superseded'] ?? null) === true
                || (is_string($supersededAt) ? trim($supersededAt) !== '' : $supersededAt !== null);

            return ! in_array((int) $document->id, self::HISTORICAL_SYNTHETIC_IDS, true)
                && ! in_array($storedType, ['nar1', 'business_registration', 'business_registration_doc', 'proof_of_business_address'], true)
                && $document->status === 'approved'
                && $factual
                && ! $synthetic
                && ! $historical
                && ! $superseded
                && in_array($purpose, $purposes, true)
                && is_string($fileId)
                && Str::isUuid($fileId)
                && strtoupper(trim((string) ($metadata['nium_file_state'] ?? ''))) === 'AVAILABLE';
        });
    }
}
