<?php

namespace App\Providers;

use App\Contracts\Aml\AmlScreeningProvider;
use App\Models\AmlScreening;
use App\Models\BankAccount;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\NiumRfiCase;
use App\Models\Transfer;
use App\Models\User;
use App\Policies\AmlScreeningPolicy;
use App\Policies\BankAccountPolicy;
use App\Policies\BalancePolicy;
use App\Policies\BeneficiaryPolicy;
use App\Policies\IntegrationProviderPolicy;
use App\Policies\KycProfilePolicy;
use App\Policies\NiumRfiCasePolicy;
use App\Policies\TransferPolicy;
use App\Policies\UserPolicy;
use App\Services\Aml\UnavailableAmlScreeningProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AmlScreeningProvider::class, UnavailableAmlScreeningProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Transfer::class, TransferPolicy::class);
        Gate::policy(Beneficiary::class, BeneficiaryPolicy::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(Balance::class, BalancePolicy::class);
        Gate::policy(IntegrationProvider::class, IntegrationProviderPolicy::class);
        Gate::policy(KycProfile::class, KycProfilePolicy::class);
        Gate::policy(AmlScreening::class, AmlScreeningPolicy::class);
        Gate::policy(NiumRfiCase::class, NiumRfiCasePolicy::class);
    }
}
