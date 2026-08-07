#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Nium\NiumCustomerDocumentResolver;
use App\Services\Nium\NiumCustomerPayloadFactory;
use App\Services\Nium\NiumHkCustomerPayloadGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    if (! $app->environment('staging') || ($argv[1] ?? '') !== '--execute=NIUM_HK_LOCAL_PAYLOAD_GATE_APPROVED') {
        throw new RuntimeException('Exact staging approval marker is required.');
    }

    Http::preventStrayRequests();
    $before = ApiRequestLog::query()->count();
    $user = User::query()->whereKey(9)->firstOrFail();
    $payload = $app->make(NiumCustomerPayloadFactory::class)->build($user, (string) Str::uuid());
    $profile = $user->kycProfile;
    NiumHkCustomerPayloadGate::assertRegions(
        config('services.nium.regulatory_region'),
        $profile->registered_country_code,
        $profile->country_code,
        data_get($profile->metadata, 'nium_region'),
        $payload['region'] ?? null,
        $payload['registeredCountry'] ?? null,
    );
    $selected = $app->make(NiumCustomerDocumentResolver::class)->forProfile($user->kycProfile)->pluck('id')->sort()->values()->all();
    $fileIds = collect([$payload['documents'], $payload['applicant']['documents'], $payload['stakeholders']['individual'][0]['documents']])
        ->flatten(1)->flatMap(fn (array $document): array => $document['fileIds'] ?? [])->all();
    $providerId = IntegrationProvider::query()->where('code', 'nium')->sole()->id;
    $customerPosts = ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', 9)
        ->where('operation', 'customer_create')->where('request_method', 'POST')->count();

    if (
        $payload['type'] !== 'corporate' || $payload['region'] !== 'HK' || $payload['kycType'] !== 'full'
        || $payload['registeredCountry'] !== 'HK' || $selected !== [21, 22, 23]
        || count($payload['documents']) !== 1 || count($payload['applicant']['documents']) !== 1
        || count($payload['stakeholders']['individual'][0]['documents']) !== 1
        || count($fileIds) !== 3 || count(array_unique($fileIds)) !== 3
        || $customerPosts !== 3 || ApiRequestLog::query()->count() !== $before
    ) {
        throw new RuntimeException('HK local Customer V5 payload gate failed.');
    }

    fwrite(STDOUT, json_encode([
        'status' => 'PASS_LOCAL_CUSTOMER_V5_GATE',
        'configured_regulatory_region' => 'HK',
        'factual_region' => 'HK',
        'payload_region' => 'HK',
        'selected_document_ids' => $selected,
        'historical_documents_selected' => false,
        'file_id_count' => 3,
        'unique_file_id_count' => 3,
        'file_id_fingerprints' => collect($fileIds)->map(fn (string $id): string => substr(hash('sha256', $id), 0, 16))->all(),
        'customer_post_count' => 3,
        'provider_http_count' => 0,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, 'NIUM_HK_LOCAL_PAYLOAD_GATE_EXIT_50'.PHP_EOL);
    exit(50);
}
