<?php

namespace App\Services\Aml;

use App\Contracts\Aml\AmlScreeningProvider;
use App\Data\Aml\AmlScreeningRequest;
use App\Data\Aml\AmlScreeningResult;
use RuntimeException;

class UnavailableAmlScreeningProvider implements AmlScreeningProvider
{
    public function name(): string
    {
        return 'unconfigured';
    }

    public function screen(AmlScreeningRequest $request): AmlScreeningResult
    {
        throw new RuntimeException('AML screening provider is not configured.');
    }
}
