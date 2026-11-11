<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

enum PricingStage: string
{
    case Contracted = 'CONTRACTED';
    case Effective = 'EFFECTIVE';
    case ProviderQuote = 'PROVIDER_QUOTE';
    case CustomerConfirmed = 'CUSTOMER_CONFIRMED';
}
