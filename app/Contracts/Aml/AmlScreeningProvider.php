<?php

namespace App\Contracts\Aml;

use App\Data\Aml\AmlScreeningRequest;
use App\Data\Aml\AmlScreeningResult;

interface AmlScreeningProvider
{
    public function name(): string;

    public function screen(AmlScreeningRequest $request): AmlScreeningResult;
}
