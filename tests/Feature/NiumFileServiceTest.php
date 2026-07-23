<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Integrations\ProviderHttpClient;
use App\Services\Nium\NiumFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NiumFileServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CREATE_FILE_ID = '11111111-1111-4111-8111-111111111111';

    private const AVAILABLE_FILE_ID = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kyc_private');
        IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        config()->set('services.kyc.documents_disk', 'kyc_private');
        config()->set('services.nium.timeout', 30);
        config()->set('services.nium.client_id', 'sandbox-client-hash-id');
        config()->set('services.nium.auth.header_value', 'sandbox-file-api-key');
        config()->set('services.nium.file_base_url', 'https://document-storage-sandbox.nium.test');
        config()->set('services.nium.file_create_endpoint', '/api/v1/client/{clientHashId}/files');
        config()->set('services.nium.file_details_endpoint', '/api/v1/client/{clientHashId}/files/{fileId}');
    }

    public function test_multipart_create_sends_streamed_file_and_json_metadata(): void
    {
        [$document, $user] = $this->createDocument(
            type: 'business_registration',
            originalName: 'business-registration.png',
            mimeType: 'image/png',
        );
        $inspected = false;

        Http::fake(function (Request $request) use (&$inspected) {
            $parts = collect($request->data());
            $filePart = $parts->firstWhere('name', 'file');
            $metadataPart = $parts->firstWhere('name', 'metadata');

            $this->assertSame('POST', $request->method());
            $this->assertSame(
                'https://document-storage-sandbox.nium.test/api/v1/client/sandbox-client-hash-id/files',
                $request->url(),
            );
            $this->assertTrue($request->isMultipart());
            $this->assertTrue($request->hasHeader('x-api-key', 'sandbox-file-api-key'));
            $this->assertTrue(Str::isUuid($request->header('x-request-id')[0] ?? ''));
            $this->assertIsArray($filePart);
            $this->assertSame('business-registration.png', $filePart['filename']);
            $this->assertSame('image/png', $filePart['headers']['Content-Type']);
            $this->assertTrue(is_resource($filePart['contents']));
            $this->assertIsArray($metadataPart);
            $this->assertSame('application/json', $metadataPart['headers']['Content-Type']);

            $metadata = json_decode($metadataPart['contents'], true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame([
                'documentType',
                'fileName',
                'fileType',
                'description',
                'environment',
                'isVerified',
                'label',
            ], array_keys($metadata));
            $this->assertSame('business_registration_doc', $metadata['documentType']);
            $this->assertSame('business-registration.png', $metadata['fileName']);
            $this->assertSame('image/png', $metadata['fileType']);
            $this->assertSame('KYB document', $metadata['description']);
            $this->assertSame('sandbox', $metadata['environment']);
            $this->assertFalse($metadata['isVerified']);
            $this->assertSame('business-registration', $metadata['label']);
            $this->assertArrayNotHasKey('document_number', $metadata);
            $this->assertArrayNotHasKey('file_path', $metadata);

            $inspected = true;

            return Http::response([
                'id' => self::CREATE_FILE_ID,
                'description' => 'KYC document',
                'state' => 'PROCESSING',
                'ignored' => 'not-returned',
            ], 201);
        });

        $result = app(NiumFileService::class)->createFile($document, $user);

        $this->assertTrue($inspected);
        $this->assertSame([
            'id' => self::CREATE_FILE_ID,
            'description' => 'KYC document',
            'state' => 'PROCESSING',
        ], $result);
    }

    public function test_successful_create_preserves_metadata_and_never_persists_secrets_or_file_contents(): void
    {
        $rawFileContents = 'raw-passport-file-contents-that-must-never-be-logged';
        [$document, $user] = $this->createDocument(
            type: 'passport_front',
            contents: $rawFileContents,
            metadata: [
                'existing_key' => 'existing-value',
                'review_source' => 'internal',
            ],
        );

        Http::fake([
            '*' => Http::response([
                'id' => self::CREATE_FILE_ID,
                'state' => 'PROCESSING',
                'storagePath' => '/remote/private/path',
            ], 201),
        ]);

        app(NiumFileService::class)->createFile($document, $user);

        $metadata = (array) $document->fresh()->metadata;
        $this->assertSame('existing-value', $metadata['existing_key']);
        $this->assertSame('internal', $metadata['review_source']);
        $this->assertSame(self::CREATE_FILE_ID, $metadata['nium_file_id']);
        $this->assertSame('PROCESSING', $metadata['nium_file_state']);
        $this->assertNotEmpty($metadata['nium_uploaded_at']);
        $this->assertNotEmpty($metadata['nium_last_checked_at']);

        $serializedMetadata = json_encode($metadata, JSON_THROW_ON_ERROR);
        $serializedLogs = json_encode(ApiRequestLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('sandbox-file-api-key', $serializedMetadata);
        $this->assertStringNotContainsString('sandbox-file-api-key', $serializedLogs);
        $this->assertStringNotContainsString($rawFileContents, $serializedLogs);
        $this->assertStringNotContainsString('documents/passport-front.png', $serializedLogs);
        $this->assertStringNotContainsString('/remote/private/path', $serializedLogs);
        $this->assertSame(1, ApiRequestLog::query()->count());
    }

    public function test_successful_fetch_returns_only_sanitized_available_details(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => self::AVAILABLE_FILE_ID,
                'name' => 'document.png',
                'size' => 68,
                'mimeType' => 'image/png',
                'storagePath' => '/remote/private/storage/path',
                'state' => 'AVAILABLE',
            ]),
        ]);

        $result = app(NiumFileService::class)->fetchFileDetails(self::AVAILABLE_FILE_ID);

        $this->assertSame([
            'id' => self::AVAILABLE_FILE_ID,
            'name' => 'document.png',
            'size' => 68,
            'mimeType' => 'image/png',
            'state' => 'AVAILABLE',
        ], $result);
        $this->assertArrayNotHasKey('storagePath', $result);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://document-storage-sandbox.nium.test/api/v1/client/sandbox-client-hash-id/files/'.self::AVAILABLE_FILE_ID
            && $request->hasHeader('x-api-key', 'sandbox-file-api-key')
            && Str::isUuid($request->header('x-request-id')[0] ?? ''));
    }

    public function test_refresh_document_state_updates_processing_to_available_and_preserves_metadata(): void
    {
        [$document, $user] = $this->createDocument(metadata: [
            'existing_key' => 'existing-value',
            'nium_file_id' => self::AVAILABLE_FILE_ID,
            'nium_file_state' => 'PROCESSING',
        ]);

        Http::fake([
            '*' => Http::response([
                'id' => self::AVAILABLE_FILE_ID,
                'name' => 'passport-front.png',
                'size' => 1234,
                'mimeType' => 'image/png',
                'storagePath' => '/must/not/be/stored',
                'state' => 'AVAILABLE',
            ]),
        ]);

        $result = app(NiumFileService::class)->refreshDocumentState($document, $user);
        $metadata = (array) $document->fresh()->metadata;

        $this->assertSame('AVAILABLE', $result['state']);
        $this->assertSame('existing-value', $metadata['existing_key']);
        $this->assertSame(self::AVAILABLE_FILE_ID, $metadata['nium_file_id']);
        $this->assertSame('AVAILABLE', $metadata['nium_file_state']);
        $this->assertSame(1234, $metadata['nium_file_size']);
        $this->assertSame('image/png', $metadata['nium_file_mime_type']);
        $this->assertNotEmpty($metadata['nium_last_checked_at']);
        $this->assertArrayNotHasKey('storagePath', $metadata);
    }

    public function test_missing_local_file_is_rejected_before_any_http_request(): void
    {
        [$document, $user] = $this->createDocument(storeFile: false);
        Http::fake();

        try {
            app(NiumFileService::class)->createFile($document, $user);
            $this->fail('Expected missing local file to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The KYC document file is not available for Nium upload.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 0);
    }

    public function test_non_successful_create_response_throws_without_exposing_raw_response(): void
    {
        [$document, $user] = $this->createDocument();
        Http::fake([
            '*' => Http::response([
                'message' => 'provider-secret-error',
                'passportNumber' => 'sensitive-passport-number',
            ], 422),
        ]);

        try {
            app(NiumFileService::class)->createFile($document, $user);
            $this->fail('Expected non-2xx response to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Nium file creation failed with HTTP status 422.', $exception->getMessage());
            $this->assertStringNotContainsString('provider-secret-error', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-passport-number', $exception->getMessage());
        }

        $serializedLogs = json_encode(ApiRequestLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('provider-secret-error', $serializedLogs);
        $this->assertStringNotContainsString('sensitive-passport-number', $serializedLogs);
    }

    public function test_successful_create_response_without_id_throws(): void
    {
        [$document, $user] = $this->createDocument();
        Http::fake(['*' => Http::response(['state' => 'PROCESSING'], 201)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium file creation response did not include an id.');

        app(NiumFileService::class)->createFile($document, $user);
    }

    public function test_fetch_response_with_mismatching_id_throws(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => self::CREATE_FILE_ID,
                'state' => 'AVAILABLE',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium file details returned a mismatching file id.');

        app(NiumFileService::class)->fetchFileDetails(self::AVAILABLE_FILE_ID);
    }

    public function test_invalid_fetch_id_is_rejected_before_http_request(): void
    {
        Http::fake();

        try {
            app(NiumFileService::class)->fetchFileDetails('../not-a-uuid');
            $this->fail('Expected invalid file id to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The Nium file id is invalid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_existing_provider_http_client_json_behavior_is_unchanged(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'json_control',
            'name' => 'JSON Control',
            'status' => 'active',
        ]);
        config()->set('services.json_control.base_url', 'https://json-control.example.test');
        config()->set('services.json_control.timeout', 30);
        Http::fake([
            '*' => Http::response(['ok' => true]),
        ]);

        $response = (new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'json_control',
        ))->post('/resource', ['alpha' => 'beta']);

        $this->assertTrue($response->successful());
        Http::assertSent(fn (Request $request): bool => $request->isJson()
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['alpha' => 'beta']);
    }

    public function test_artisan_command_uploads_only_an_existing_document_and_prints_safe_fields(): void
    {
        [$document] = $this->createDocument();
        Http::fake([
            '*' => Http::response([
                'id' => self::CREATE_FILE_ID,
                'state' => 'PROCESSING',
            ], 201),
        ]);

        $exitCode = Artisan::call('nium:file:test', [
            'kycDocumentId' => $document->id,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            "KYC document ID: {$document->id}\nNium file ID: ".self::CREATE_FILE_ID."\nState: PROCESSING\n",
            $output,
        );
        $this->assertStringNotContainsString('sandbox-file-api-key', $output);
        $this->assertStringNotContainsString((string) $document->file_path, $output);
        $this->assertStringNotContainsString((string) $document->document_number, $output);
    }

    public function test_artisan_command_refuses_to_run_in_production(): void
    {
        [$document] = $this->createDocument();
        config()->set('app.env', 'production');
        Http::fake();

        $exitCode = Artisan::call('nium:file:test', [
            'kycDocumentId' => $document->id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('disabled in production', Artisan::output());
        Http::assertNothingSent();
    }

    /**
     * @return array{KycDocument, User}
     */
    private function createDocument(
        string $type = 'passport_front',
        string $contents = 'safe-synthetic-file-bytes',
        string $originalName = 'passport-front.png',
        string $mimeType = 'image/png',
        array $metadata = ['existing_key' => 'existing-value'],
        bool $storeFile = true,
    ): array {
        $user = User::factory()->create();
        $profile = KycProfile::query()->create([
            'user_id' => $user->id,
            'status' => 'submitted',
            'applicant_type' => 'individual',
            'legal_name' => 'Synthetic Test User',
            'address_line1' => '100 Test Avenue',
            'city' => 'Testville',
            'country_code' => 'HK',
        ]);
        $path = 'documents/passport-front.png';

        if ($storeFile) {
            Storage::disk('kyc_private')->put($path, $contents);
        }

        $document = KycDocument::query()->create([
            'kyc_profile_id' => $profile->id,
            'type' => $type,
            'status' => 'submitted',
            'file_url' => 'https://api.example.test/kyc/document',
            'storage_disk' => 'kyc_private',
            'file_path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => strlen($contents),
            'document_number' => 'SYNTHETIC-DOCUMENT-NUMBER',
            'metadata' => $metadata,
        ]);

        return [$document, $user];
    }
}
