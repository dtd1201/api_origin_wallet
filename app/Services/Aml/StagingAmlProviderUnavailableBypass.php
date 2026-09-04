<?php

namespace App\Services\Aml;

use App\Models\AmlScreening;
use Illuminate\Support\Collection;

class StagingAmlProviderUnavailableBypass
{
    public const REASON = 'staging_aml_provider_unavailable_bypass';

    /** @param Collection<int, AmlScreening> $screenings */
    public function applies(Collection $screenings): bool
    {
        return app()->environment('staging')
            && $screenings->isNotEmpty()
            && $screenings->every(fn (AmlScreening $screening): bool => $screening->screening_provider === 'unconfigured'
                && $screening->provider === 'unconfigured'
                && data_get($screening->result_summary, 'error') === 'provider_failure'
            );
    }
}
