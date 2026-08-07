<?php

namespace Tests\Unit;

use App\Services\Nium\NiumHkFileStageExitCodes;
use RuntimeException;
use Tests\TestCase;

class NiumHkFileStageExitCodesTest extends TestCase
{
    public function test_pass_hold_unknown_rejection_and_preflight_exit_codes_are_non_ambiguous(): void
    {
        $this->assertSame(0, NiumHkFileStageExitCodes::forStatus('PASS_FILE_AVAILABLE'));
        $this->assertSame(0, NiumHkFileStageExitCodes::forStatus('PASS_DOCUMENT_AVAILABLE'));
        $this->assertSame(20, NiumHkFileStageExitCodes::forStatus('HOLD_FILE_NOT_AVAILABLE'));
        $this->assertSame(40, NiumHkFileStageExitCodes::forStatus('HOLD_DETAILS_OUTCOME_UNKNOWN'));
        $this->assertSame(30, NiumHkFileStageExitCodes::forException(new RuntimeException('File Create was rejected.')));
        $this->assertSame(40, NiumHkFileStageExitCodes::forException(new RuntimeException('File Create outcome is unknown.')));
        $this->assertSame(20, NiumHkFileStageExitCodes::forException(new RuntimeException('File Details is not AVAILABLE.')));
        $this->assertSame(50, NiumHkFileStageExitCodes::forException(new RuntimeException('Preflight failed.')));
    }

    public function test_cli_wrappers_exit_with_the_classified_code(): void
    {
        foreach ([
            'scripts/nium/run_hk_sandbox_file_stage.php',
            'scripts/nium/continue_hk_sandbox_file_details.php',
            'scripts/nium/resume_hk_sandbox_file_stage.php',
            'scripts/nium/continue_hk_sandbox_file_details_22.php',
            'scripts/nium/resume_hk_sandbox_file_stage_23.php',
            'scripts/nium/continue_hk_sandbox_file_details_23.php',
        ] as $path) {
            $script = file_get_contents(base_path($path));
            $this->assertIsString($script);
            $this->assertStringContainsString('NiumHkFileStageExitCodes::', $script);
            $this->assertStringContainsString('exit($exitCode)', $script);
        }
    }
}
