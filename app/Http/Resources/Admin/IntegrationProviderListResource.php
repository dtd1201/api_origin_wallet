<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class IntegrationProviderListResource extends ProviderSummaryResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_available_for_onboarding' => $this->resource->isAvailableForOnboarding(),
            'supports_beneficiaries' => $this->resource->supportsBeneficiaries(),
            'supports_data_sync' => $this->resource->supportsDataSync(),
            'supports_quotes' => $this->resource->supportsQuotes(),
            'supports_transfers' => $this->resource->supportsTransfers(),
            'supports_webhooks' => $this->resource->supportsWebhooks(),
            'is_configured' => $this->resource->isConfigured(),
        ];
    }
}
