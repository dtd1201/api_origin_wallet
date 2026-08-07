<?php

declare(strict_types=1);

const NIUM_HK_FIXTURE_MARKER = 'nium-corporate-synthetic-v5-hk';
const NIUM_HK_PAGE_WIDTH = 612;
const NIUM_HK_PAGE_HEIGHT = 792;

/**
 * @return array<string, mixed>
 */
function generateNiumHkSandboxDocuments(string $outputDirectory): array
{
    $outputDirectory = rtrim($outputDirectory, DIRECTORY_SEPARATOR);

    if ($outputDirectory === '') {
        throw new RuntimeException('An isolated output directory is required.');
    }

    if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0700, true) && ! is_dir($outputDirectory)) {
        throw new RuntimeException('Unable to create the isolated output directory.');
    }

    $definitions = [
        [
            'logical_role' => 'corporate_registration',
            'intended_nium_document_type' => 'business_registration_doc',
            'filename' => 'hk-corporate-sandbox-test.pdf',
            'lines' => [
                'SANDBOX TEST ONLY',
                'NOT A REAL DOCUMENT',
                'SYNTHETIC CORPORATE INTEGRATION FIXTURE',
                'Entity Type: Corporate',
                'Regulatory Region: Hong Kong',
                'Country: Hong Kong',
                'Fixture: Origin Wallet Nium HK Sandbox V5',
                'Contains no registration number, address, or real entity data.',
            ],
        ],
        [
            'logical_role' => 'applicant_authorized_person_identity',
            'intended_nium_document_type' => 'passport',
            'filename' => 'hk-applicant-sandbox-test.pdf',
            'lines' => [
                'SANDBOX TEST ONLY',
                'NOT A REAL DOCUMENT',
                'SYNTHETIC IDENTITY INTEGRATION FIXTURE',
                'Role: Authorized Person',
                'Country Context: Hong Kong',
                'Fixture: Origin Wallet Nium HK Sandbox V5',
                'Contains no name, identity number, birth date, or real PII.',
            ],
        ],
        [
            'logical_role' => 'beneficial_owner_stakeholder_identity',
            'intended_nium_document_type' => 'passport',
            'filename' => 'hk-stakeholder-sandbox-test.pdf',
            'lines' => [
                'SANDBOX TEST ONLY',
                'NOT A REAL DOCUMENT',
                'SYNTHETIC IDENTITY INTEGRATION FIXTURE',
                'Role: Beneficial Owner / Stakeholder',
                'Country Context: Hong Kong',
                'Fixture: Origin Wallet Nium HK Sandbox V5',
                'Contains no name, identity number, birth date, or real PII.',
            ],
        ],
    ];

    $artifacts = [];

    foreach ($definitions as $definition) {
        $path = $outputDirectory.DIRECTORY_SEPARATOR.$definition['filename'];
        $bytes = niumHkSyntheticPdf($definition['lines']);

        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException("Unable to write synthetic artifact [{$definition['filename']}].");
        }

        chmod($path, 0600);
        $artifacts[] = [
            'logical_role' => $definition['logical_role'],
            'intended_nium_document_type' => $definition['intended_nium_document_type'],
            'external_local_path' => $path,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'page_dimensions_points' => [
                'width' => NIUM_HK_PAGE_WIDTH,
                'height' => NIUM_HK_PAGE_HEIGHT,
            ],
            'sha256' => hash('sha256', $bytes),
            'fixture_marker' => NIUM_HK_FIXTURE_MARKER,
            'visibly_test_only' => true,
            'target_region' => 'HK',
        ];
    }

    $manifest = [
        'schema_version' => 1,
        'fixture_marker' => NIUM_HK_FIXTURE_MARKER,
        'target_region' => 'HK',
        'generated_artifacts' => $artifacts,
    ];
    $manifestPath = $outputDirectory.DIRECTORY_SEPARATOR.'manifest.json';
    $manifestBytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    if (file_put_contents($manifestPath, $manifestBytes, LOCK_EX) !== strlen($manifestBytes)) {
        throw new RuntimeException('Unable to write the synthetic artifact manifest.');
    }

    chmod($manifestPath, 0600);

    return [...$manifest, 'manifest_path' => $manifestPath];
}

/**
 * @param  list<string>  $lines
 */
function niumHkSyntheticPdf(array $lines): string
{
    $content = "0.94 0.97 1 rg\n36 36 540 720 re f\n";
    $content .= "0.75 0.12 0.12 rg\n48 660 516 84 re f\n";
    $content .= "BT\n/F1 26 Tf\n1 1 1 rg\n72 708 Td\n(".niumHkPdfEscape($lines[0]).") Tj\n";
    $content .= "0 -34 Td\n(".niumHkPdfEscape($lines[1]).") Tj\nET\n";
    $content .= "BT\n/F1 15 Tf\n0.08 0.16 0.24 rg\n72 620 Td\n";

    foreach (array_slice($lines, 2) as $index => $line) {
        if ($index > 0) {
            $content .= "0 -34 Td\n";
        }

        $content .= '('.niumHkPdfEscape($line).") Tj\n";
    }

    $content .= "ET\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.NIUM_HK_PAGE_WIDTH.' '.NIUM_HK_PAGE_HEIGHT.'] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n% Synthetic Nium Sandbox Fixture\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $number = $index + 1;
        $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
}

function niumHkPdfEscape(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $directory = $argv[1] ?? '/home/dtd1201/projects/nium_fixture_v5_hk_documents';
    $manifest = generateNiumHkSandboxDocuments($directory);

    fwrite(STDOUT, json_encode([
        'fixture_marker' => $manifest['fixture_marker'],
        'artifact_count' => count($manifest['generated_artifacts']),
        'manifest_path' => $manifest['manifest_path'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
}
