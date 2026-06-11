<?php

namespace App\Services\Kyc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

class BusinessRegistryVerificationService
{
    /**
     * @return array<string, mixed>
     */
    public function verify(
        string $countryCode,
        ?string $businessRegistrationNumber,
        ?string $taxId,
        ?string $businessName = null,
    ): array {
        $countryCode = strtoupper(trim($countryCode));
        $businessRegistrationNumber = $this->normalizeIdentifier((string) $businessRegistrationNumber);
        $taxId = $this->normalizeIdentifier((string) $taxId);

        if ($countryCode === '') {
            return $this->unavailable('unknown', 'Country is required before registry verification.');
        }

        try {
            if ($countryCode === 'AU') {
                return $this->verifyAustralia($businessRegistrationNumber, $taxId, $businessName);
            }

            if ($countryCode === 'SG') {
                return $this->verifySingapore($businessRegistrationNumber, $taxId, $businessName);
            }

            if ($countryCode === 'VN') {
                return $this->verifyVietnam($businessRegistrationNumber, $taxId, $businessName);
            }

            if ($this->isViesCountry($countryCode)) {
                return $this->verifyViesVat($countryCode, $taxId ?: $businessRegistrationNumber, $businessName);
            }
        } catch (ConnectionException|RequestException $exception) {
            return $this->error(
                $this->sourceForCountry($countryCode),
                'Registry verification service could not be reached.',
                ['error' => $exception->getMessage()],
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $this->sourceForCountry($countryCode),
                'Registry verification returned an unexpected response.',
                ['error' => $exception->getMessage()],
            );
        }

        return $this->unavailable(
            $this->sourceForCountry($countryCode),
            'No official automated registry verification adapter is configured for this country.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyAustralia(string $businessRegistrationNumber, string $taxId, ?string $businessName): array
    {
        $identifier = preg_replace('/\D+/', '', $taxId ?: $businessRegistrationNumber) ?? '';
        $guid = (string) config('services.business_registry.au.guid', '');

        if ($guid === '') {
            return $this->unavailable(
                'Australian Business Register ABN Lookup',
                'ABN Lookup requires an authentication GUID before live checks can run.',
                [
                    'source_url' => 'https://abr.business.gov.au/Tools/WebServices',
                    'registration_url' => 'https://abr.business.gov.au/Tools/WebServicesRegister',
                ],
            );
        }

        if (! in_array(strlen($identifier), [9, 11], true)) {
            return $this->invalid(
                'Australian Business Register ABN Lookup',
                'Australian ABN must be 11 digits or ACN must be 9 digits.',
                ['source_url' => 'https://abr.business.gov.au/Tools/WebServices'],
            );
        }

        $endpoint = strlen($identifier) === 9 ? 'AcnDetails.aspx' : 'AbnDetails.aspx';
        $parameterName = strlen($identifier) === 9 ? 'acn' : 'abn';
        $url = rtrim((string) config('services.business_registry.au.json_url'), '/').'/'.$endpoint;
        $response = Http::timeout((int) config('services.business_registry.timeout', 12))
            ->get($url, [
                $parameterName => $identifier,
                'guid' => $guid,
                'callback' => 'callback',
            ])
            ->throw();
        $payload = $this->parseJsonp($response->body());
        $abn = $this->normalizeIdentifier((string) Arr::get($payload, 'Abn', ''));
        $acn = $this->normalizeIdentifier((string) Arr::get($payload, 'Acn', ''));
        $entityName = trim((string) Arr::get($payload, 'EntityName', ''));
        $status = trim((string) Arr::get($payload, 'AbnStatus', ''));
        $matchedIdentifier = strlen($identifier) === 9 ? $acn : $abn;

        if ($matchedIdentifier === '' || $matchedIdentifier !== $identifier) {
            return $this->invalid(
                'Australian Business Register ABN Lookup',
                'No active Australian registry record was found for this identifier.',
                [
                    'checked_identifier' => $identifier,
                    'source_url' => 'https://abr.business.gov.au/Tools/WebServices',
                    'raw' => $payload,
                ],
            );
        }

        return $this->verified(
            'Australian Business Register ABN Lookup',
            'Australian registry identifier exists.',
            [
                'identifier' => $identifier,
                'business_name' => $entityName,
                'registry_status' => $status,
                'name_match' => $this->nameMatch($businessName, $entityName),
                'source_url' => 'https://abr.business.gov.au/Tools/WebServices',
                'raw' => $payload,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifySingapore(string $businessRegistrationNumber, string $taxId, ?string $businessName): array
    {
        $identifier = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $businessRegistrationNumber ?: $taxId) ?? '');
        $datasetMap = (array) config('services.business_registry.sg.dataset_ids', []);
        $datasetId = $this->singaporeDatasetId($identifier, $datasetMap);

        if ($identifier === '') {
            return $this->invalid(
                'ACRA / data.gov.sg',
                'Singapore UEN is required for live registry verification.',
                ['source_url' => 'https://data.gov.sg/collections/2/view'],
            );
        }

        if ($datasetId === null) {
            return $this->unavailable(
                'ACRA / data.gov.sg',
                'Singapore ACRA dataset mapping is not configured for this UEN prefix.',
                ['source_url' => 'https://data.gov.sg/collections/2/view'],
            );
        }

        $response = Http::timeout((int) config('services.business_registry.timeout', 12))
            ->get((string) config('services.business_registry.sg.datastore_url'), [
                'resource_id' => $datasetId,
                'limit' => 1,
                'filters' => json_encode(['uen' => $identifier], JSON_THROW_ON_ERROR),
            ])
            ->throw()
            ->json();

        $record = Arr::first((array) Arr::get($response, 'result.records', []));

        if (! is_array($record)) {
            return $this->invalid(
                'ACRA / data.gov.sg',
                'No Singapore ACRA record was found for this UEN.',
                [
                    'identifier' => $identifier,
                    'source_url' => 'https://data.gov.sg/collections/2/view',
                ],
            );
        }

        $entityName = trim((string) ($record['entity_name'] ?? $record['entity_name_text'] ?? ''));

        return $this->verified(
            'ACRA / data.gov.sg',
            'Singapore UEN exists in ACRA open data.',
            [
                'identifier' => $identifier,
                'business_name' => $entityName,
                'registry_status' => $record['entity_status_description'] ?? $record['entity_status_description_text'] ?? null,
                'name_match' => $this->nameMatch($businessName, $entityName),
                'source_url' => 'https://data.gov.sg/collections/2/view',
                'raw' => $record,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyVietnam(string $businessRegistrationNumber, string $taxId, ?string $businessName): array
    {
        $identifier = preg_replace('/\D+/', '', $taxId ?: $businessRegistrationNumber) ?? '';

        if (! in_array(strlen($identifier), [10, 13], true)) {
            return $this->invalid(
                'Vietnam General Department of Taxation',
                'Vietnam tax ID must contain 10 or 13 digits.',
                ['source_url' => 'https://tracuunnt.gdt.gov.vn/tcnnt/mstdn.jsp'],
            );
        }

        return $this->unavailable(
            'Vietnam General Department of Taxation',
            'The official public Vietnam tax lookup requires CAPTCHA, so automated live verification needs an authorized data provider or manual review.',
            [
                'identifier' => $identifier,
                'business_name' => $businessName,
                'source_url' => 'https://tracuunnt.gdt.gov.vn/tcnnt/mstdn.jsp',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyViesVat(string $countryCode, string $taxId, ?string $businessName): array
    {
        $viesCountryCode = $this->viesCountryCode($countryCode);
        $vatNumber = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $taxId) ?? '');

        if (Str::startsWith($vatNumber, $viesCountryCode)) {
            $vatNumber = substr($vatNumber, strlen($viesCountryCode));
        }

        if ($vatNumber === '') {
            return $this->invalid(
                'European Commission VIES',
                'VAT number is required for VIES verification.',
                ['source_url' => 'https://europa.eu/youreurope/business/taxation/vat/check-vat-number-vies/index_en.htm'],
            );
        }

        $response = Http::timeout((int) config('services.business_registry.timeout', 12))
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '',
            ])
            ->send('POST', (string) config('services.business_registry.eu_vies.endpoint'), [
                'body' => $this->viesEnvelope($viesCountryCode, $vatNumber),
            ])
            ->throw();

        $payload = $this->parseViesResponse($response->body());

        if (($payload['valid'] ?? false) !== true) {
            return $this->invalid(
                'European Commission VIES',
                'VAT number was not found as valid in VIES.',
                [
                    'country_code' => $viesCountryCode,
                    'identifier' => $vatNumber,
                    'source_url' => 'https://europa.eu/youreurope/business/taxation/vat/check-vat-number-vies/index_en.htm',
                    'raw' => $payload,
                ],
            );
        }

        return $this->verified(
            'European Commission VIES',
            'VAT number exists in the relevant national VAT database through VIES.',
            [
                'country_code' => $viesCountryCode,
                'identifier' => $vatNumber,
                'business_name' => $payload['name'] ?? null,
                'address' => $payload['address'] ?? null,
                'name_match' => $this->nameMatch($businessName, $payload['name'] ?? null),
                'source_url' => 'https://europa.eu/youreurope/business/taxation/vat/check-vat-number-vies/index_en.htm',
                'raw' => $payload,
            ],
        );
    }

    private function isViesCountry(string $countryCode): bool
    {
        return in_array($this->viesCountryCode($countryCode), (array) config('services.business_registry.eu_vies.countries', []), true);
    }

    private function viesCountryCode(string $countryCode): string
    {
        return $countryCode === 'GR' ? 'EL' : $countryCode;
    }

    private function viesEnvelope(string $countryCode, string $vatNumber): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:ec.europa.eu:taxud:vies:services:checkVat:types">
  <soapenv:Header/>
  <soapenv:Body>
    <urn:checkVat>
      <urn:countryCode>{$countryCode}</urn:countryCode>
      <urn:vatNumber>{$vatNumber}</urn:vatNumber>
    </urn:checkVat>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseViesResponse(string $body): array
    {
        $xml = new SimpleXMLElement($body);
        $valueFor = static function (string $name) use ($xml): ?string {
            $nodes = $xml->xpath('//*[local-name()="'.$name.'"]');
            $value = is_array($nodes) && isset($nodes[0]) ? trim((string) $nodes[0]) : '';

            return $value === '---' || $value === '' ? null : $value;
        };

        return [
            'country_code' => $valueFor('countryCode'),
            'vat_number' => $valueFor('vatNumber'),
            'request_date' => $valueFor('requestDate'),
            'valid' => strtolower((string) $valueFor('valid')) === 'true',
            'name' => $valueFor('name'),
            'address' => $valueFor('address'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonp(string $body): array
    {
        $trimmed = trim($body);

        if (preg_match('/^[^(]+\((.*)\);?$/s', $trimmed, $matches) === 1) {
            $trimmed = $matches[1];
        }

        $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, string>  $datasetMap
     */
    private function singaporeDatasetId(string $identifier, array $datasetMap): ?string
    {
        $prefix = substr($identifier, 0, 1);

        if ($prefix !== '' && ctype_alpha($prefix) && isset($datasetMap[$prefix])) {
            return (string) $datasetMap[$prefix];
        }

        if (isset($datasetMap['others'])) {
            return (string) $datasetMap['others'];
        }

        return null;
    }

    private function normalizeIdentifier(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function sourceForCountry(string $countryCode): string
    {
        return match ($countryCode) {
            'AU' => 'Australian Business Register ABN Lookup',
            'SG' => 'ACRA / data.gov.sg',
            'VN' => 'Vietnam General Department of Taxation',
            default => $this->isViesCountry($countryCode) ? 'European Commission VIES' : 'unknown',
        };
    }

    private function nameMatch(?string $submittedName, ?string $registryName): ?bool
    {
        if (! $submittedName || ! $registryName) {
            return null;
        }

        return Str::of($submittedName)->lower()->squish()->toString() ===
            Str::of($registryName)->lower()->squish()->toString();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function verified(string $source, string $message, array $extra = []): array
    {
        return $this->result('verified', $source, $message, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function invalid(string $source, string $message, array $extra = []): array
    {
        return $this->result('invalid', $source, $message, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function unavailable(string $source, string $message, array $extra = []): array
    {
        return $this->result('unavailable', $source, $message, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function error(string $source, string $message, array $extra = []): array
    {
        return $this->result('error', $source, $message, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(string $status, string $source, string $message, array $extra = []): array
    {
        return [
            'status' => $status,
            'source' => $source,
            'message' => $message,
            'checked_at' => now()->toISOString(),
            ...$extra,
        ];
    }
}
