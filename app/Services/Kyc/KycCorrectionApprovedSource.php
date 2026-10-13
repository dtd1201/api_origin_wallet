<?php

namespace App\Services\Kyc;

use App\Models\KycProfile;

final class KycCorrectionApprovedSource
{
    public function snapshot(KycProfile $profile): array
    {
        $profile = $profile->fresh();
        $profile->loadMissing(['documents', 'relatedPersons.documents', 'companyDirectors.documents']);

        return $this->canonicalize([
            'profile' => $this->attributes($profile->getAttributes(), [
                'created_at', 'updated_at', 'submitted_at', 'reviewed_at', 'reviewed_by_user_id',
                'review_note', 'rejection_reason',
            ]),
            'documents' => $profile->documents->sortBy('id')->map(fn ($document): array =>
                $this->attributes($document->getAttributes(), ['created_at', 'updated_at'])
            )->values()->all(),
            'related_people' => $profile->relatedPersons->sortBy('id')->map(fn ($person): array => [
                'attributes' => $this->attributes($person->getAttributes(), ['created_at', 'updated_at']),
                'documents' => $person->documents->sortBy('id')->map(fn ($document): array =>
                    $this->attributes($document->getAttributes(), ['created_at', 'updated_at'])
                )->values()->all(),
            ])->values()->all(),
            'company_directors' => $profile->companyDirectors->sortBy('id')->map(fn ($director): array => [
                'attributes' => $this->attributes($director->getAttributes(), ['created_at', 'updated_at']),
                'documents' => $director->documents->sortBy('id')->map(fn ($document): array =>
                    $this->attributes($document->getAttributes(), ['created_at', 'updated_at'])
                )->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function attributes(array $attributes, array $excluded): array
    {
        return array_diff_key($attributes, array_flip($excluded));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
