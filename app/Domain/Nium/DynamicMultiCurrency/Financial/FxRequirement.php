<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

enum FxRequirement: string
{
    case None = 'NONE';
    case Required = 'REQUIRED';
    case Unknown = 'UNKNOWN';
}
