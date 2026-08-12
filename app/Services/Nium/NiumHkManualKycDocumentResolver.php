<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\KycRelatedPerson;
use Illuminate\Support\Str;

final class NiumHkManualKycDocumentResolver
{
    private const HISTORICAL_SYNTHETIC_IDS = [18, 19, 20, 21, 22, 23];

    public function resolve(KycRelatedPerson $person): array
    {
        $documents = KycDocument::query()
            ->where('kyc_profile_id', $person->kyc_profile_id)
            ->where('kyc_related_person_id', $person->id)
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

            return ! in_array((int) $document->id, self::HISTORICAL_SYNTHETIC_IDS, true)
                && ! in_array($storedType, ['nar1', 'business_registration', 'business_registration_doc', 'proof_of_business_address'], true)
                && $document->status === 'approved'
                && ($metadata['factual'] ?? null) === true
                && ($metadata['synthetic'] ?? null) === false
                && ($metadata['superseded'] ?? false) === false
                && in_array($purpose, $purposes, true)
                && is_string($fileId)
                && Str::isUuid($fileId)
                && strtoupper(trim((string) ($metadata['nium_file_state'] ?? ''))) === 'AVAILABLE';
        });
    }
}
