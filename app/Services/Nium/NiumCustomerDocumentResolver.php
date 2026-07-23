<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use Illuminate\Support\Collection;

class NiumCustomerDocumentResolver
{
    /**
     * @return Collection<int, KycDocument>
     */
    public function forProfile(KycProfile $profile): Collection
    {
        $documents = $this->profileDocuments($profile);

        if ($profile->applicant_type === 'business') {
            $documents = $documents->concat(
                $profile->relatedPersons->flatMap(
                    fn (KycRelatedPerson $person): Collection => $this->relatedPersonDocuments($person),
                ),
            );
        }

        return $documents->values();
    }

    /**
     * @return Collection<int, KycDocument>
     */
    public function profileDocuments(KycProfile $profile): Collection
    {
        return $this->selected(
            $profile->documents->whereNull('kyc_related_person_id'),
        );
    }

    /**
     * @return Collection<int, KycDocument>
     */
    public function relatedPersonDocuments(KycRelatedPerson $person): Collection
    {
        return $this->selected($person->documents);
    }

    /**
     * Use one canonical selection path for both file preparation and payload generation.
     * Only approved documents are eligible. A resubmitted document supersedes its recorded
     * predecessor, and the newest approved copy wins for an otherwise duplicate artifact.
     *
     * @param  Collection<int, KycDocument>  $documents
     * @return Collection<int, KycDocument>
     */
    private function selected(Collection $documents): Collection
    {
        $approved = $documents
            ->filter(fn (KycDocument $document): bool => in_array(
                strtolower(trim((string) $document->status)),
                ['approved', 'verified'],
                true,
            ));
        $supersededIds = $approved
            ->map(fn (KycDocument $document): mixed => ((array) $document->metadata)['previous_document_id'] ?? null)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $approved
            ->reject(fn (KycDocument $document): bool => in_array(
                (int) $document->getKey(),
                $supersededIds,
                true,
            ))
            ->sortByDesc(fn (KycDocument $document): int => (int) $document->getKey())
            ->unique(fn (KycDocument $document): string => $this->selectionKey($document))
            ->sortBy(fn (KycDocument $document): int => (int) $document->getKey())
            ->values();
    }

    private function selectionKey(KycDocument $document): string
    {
        $metadata = (array) $document->metadata;
        $type = strtolower(trim((string) ($metadata['nium_document_type'] ?? $document->type)));
        $side = strtolower(trim((string) $document->side));
        $fileHash = strtolower(trim((string) $document->file_hash));

        return $fileHash !== ''
            ? implode('|', [$type, $side, 'hash:'.$fileHash])
            : implode('|', [
                $type,
                strtolower(trim((string) $document->type)),
                $side,
                strtoupper(trim((string) $document->document_number)),
            ]);
    }
}
