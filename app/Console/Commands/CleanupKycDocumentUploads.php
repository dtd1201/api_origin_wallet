<?php

namespace App\Console\Commands;

use App\Services\Kyc\KycDocumentUploadCleanupService;
use Illuminate\Console\Command;

class CleanupKycDocumentUploads extends Command
{
    protected $signature = 'kyc:cleanup-document-uploads {--execute : Delete eligible files instead of dry-run} {--limit=100 : Maximum registry rows to inspect}';
    protected $description = 'Safely inspect or clean expired, unreferenced correction document uploads';

    public function handle(KycDocumentUploadCleanupService $service): int
    {
        $result = $service->cleanup(! (bool) $this->option('execute'), max(1, (int) $this->option('limit')));
        $this->line(json_encode(['dry_run' => ! $this->option('execute'), ...$result], JSON_THROW_ON_ERROR));
        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
