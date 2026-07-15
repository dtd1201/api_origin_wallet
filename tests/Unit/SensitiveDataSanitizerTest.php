<?php

namespace Tests\Unit;

use App\Support\SensitiveDataSanitizer;
use Tests\TestCase;

class SensitiveDataSanitizerTest extends TestCase
{
    public function test_sanitizes_provider_secret_keys(): void
    {
        $sanitized = app(SensitiveDataSanitizer::class)->sanitize([
            'x-api-key' => 'nium-api-key-value',
            'header_value' => 'another-provider-key',
            'x-partner-key' => 'nium-partner-key',
            'clientHashId' => 'nium-client-hash-id',
            'client_secret' => 'client-secret-value',
            'safe' => 'visible',
        ]);

        $this->assertSame('[REDACTED]', $sanitized['x-api-key']);
        $this->assertSame('[REDACTED]', $sanitized['header_value']);
        $this->assertSame('[REDACTED]', $sanitized['x-partner-key']);
        $this->assertSame('[REDACTED]', $sanitized['clientHashId']);
        $this->assertSame('[REDACTED]', $sanitized['client_secret']);
        $this->assertSame('visible', $sanitized['safe']);
    }

    public function test_redacts_configured_secret_values_inside_raw_strings(): void
    {
        config()->set('services.nium.auth.header_value', 'nium-live-secret-key');

        $sanitized = app(SensitiveDataSanitizer::class)->sanitize([
            'raw' => 'Provider echoed x-api-key: nium-live-secret-key in a debug response.',
        ]);

        $this->assertSame('Provider echoed x-api-key: [REDACTED] in a debug response.', $sanitized['raw']);
    }

    public function test_recursively_redacts_authentication_identity_and_document_fields(): void
    {
        $sanitized = app(SensitiveDataSanitizer::class)->sanitize([
            'authenticationCode' => 'one-time-secret',
            'customer' => [
                'firstName' => 'John',
                'email' => 'john@example.test',
                'billingAddress' => ['addressLine1' => '1 Secret Street'],
                'documents' => [[
                    'documentNumber' => 'P1234567',
                    'status' => 'verified',
                ]],
            ],
            'stakeholders' => [[
                'firstName' => 'Director',
                'dateOfBirth' => '1980-01-01',
                'identityDocument' => ['documentNumber' => 'STAKEHOLDER-ID'],
            ]],
        ]);

        $this->assertSame('[REDACTED]', $sanitized['authenticationCode']);
        $this->assertSame('[REDACTED]', $sanitized['customer']['firstName']);
        $this->assertSame('[REDACTED]', $sanitized['customer']['email']);
        $this->assertSame('[REDACTED]', $sanitized['customer']['billingAddress']['addressLine1']);
        $this->assertSame('[REDACTED]', $sanitized['customer']['documents']);
        $this->assertSame('[REDACTED]', $sanitized['stakeholders']);
    }
}
