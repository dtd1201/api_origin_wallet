<?php

use App\Http\Controllers\Api\Admin\AmlScreeningController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BankAccountController;
use App\Http\Controllers\Api\Admin\BeneficiaryController;
use App\Http\Controllers\Api\Admin\ContactSubmissionController;
use App\Http\Controllers\Api\Admin\FxOrderController;
use App\Http\Controllers\Api\Admin\IntegrationProviderController;
use App\Http\Controllers\Api\Admin\KycProviderSubmissionController;
use App\Http\Controllers\Api\Admin\LedgerEntryController;
use App\Http\Controllers\Api\Admin\ManagedExchangeRateController;
use App\Http\Controllers\Api\Admin\NiumComplianceEventController;
use App\Http\Controllers\Api\Admin\NiumRfiCaseController;
use App\Http\Controllers\Api\Admin\ProviderHealthController;
use App\Http\Controllers\Api\Admin\ProviderSyncController;
use App\Http\Controllers\Api\Admin\ProviderWebhookEventController;
use App\Http\Controllers\Api\Admin\TransactionController;
use App\Http\Controllers\Api\Admin\TransferController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\UserIntegrationLinkController;
use App\Http\Controllers\Api\Admin\UserKycSubmissionController;
use App\Http\Controllers\Api\Admin\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:users.view')->group(function (): void {
    Route::apiResource('users', UserController::class)->only(['index', 'show']);
    Route::apiResource('contact-submissions', ContactSubmissionController::class)->only(['index', 'show']);
});
Route::middleware('permission:users.manage')->group(function (): void {
    Route::apiResource('users', UserController::class)->only(['store', 'update', 'destroy']);
    Route::delete('contact-submissions/{contactSubmission}', [ContactSubmissionController::class, 'destroy'])->name('contact-submissions.destroy');
});

Route::middleware('permission:providers.view')->group(function (): void {
    Route::apiResource('integration-providers', IntegrationProviderController::class)->only(['index', 'show']);
    Route::apiResource('provider-health', ProviderHealthController::class)->only(['index'])->parameters(['provider-health' => 'provider']);
    Route::apiResource('provider-webhook-events', ProviderWebhookEventController::class)->only(['index', 'show'])->parameters(['provider-webhook-events' => 'providerWebhookEvent']);
});
Route::middleware('permission:providers.manage')->group(function (): void {
    Route::apiResource('integration-providers', IntegrationProviderController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('exchange-rates', ManagedExchangeRateController::class)->only(['store', 'update', 'destroy']);
});
Route::middleware('permission:providers.sync')->group(function (): void {
    Route::post('provider-health/{provider}/check', [ProviderHealthController::class, 'check'])->name('provider-health.check');
    Route::post('provider-webhook-events/{providerWebhookEvent}/retry', [ProviderWebhookEventController::class, 'retry'])->name('provider-webhook-events.retry');
});
Route::post('providers/{provider}/users/{user}/sync', [ProviderSyncController::class, 'syncUser'])
    ->middleware('permission:providers.sync,wallet.sync')
    ->name('providers.users.sync');

Route::middleware('permission:transactions.view')->group(function (): void {
    Route::apiResource('transactions', TransactionController::class)->only(['index', 'show']);
    Route::apiResource('exchange-rates', ManagedExchangeRateController::class)->only(['index', 'show']);
});
Route::middleware('permission:transactions.manage')->group(function (): void {
    Route::apiResource('transactions', TransactionController::class)->only(['store', 'update', 'destroy']);
});

Route::middleware('permission:bank_accounts.view')->group(function (): void {
    Route::apiResource('bank-accounts', BankAccountController::class)->only(['index', 'show']);
});
Route::middleware('permission:bank_accounts.manage')->group(function (): void {
    Route::apiResource('bank-accounts', BankAccountController::class)->only(['store', 'update', 'destroy']);
});
Route::middleware('permission:beneficiaries.view')->group(function (): void {
    Route::apiResource('beneficiaries', BeneficiaryController::class)->only(['index', 'show']);
});
Route::middleware('permission:beneficiaries.manage')->group(function (): void {
    Route::apiResource('beneficiaries', BeneficiaryController::class)->only(['store', 'update', 'destroy']);
});

Route::middleware('permission:transfers.view')->group(function (): void {
    Route::apiResource('transfers', TransferController::class)->only(['index', 'show']);
    Route::apiResource('fx-orders', FxOrderController::class)->only(['index', 'show'])->parameters(['fx-orders' => 'fxOrder']);
});
Route::middleware('permission:transfers.manage')->group(function (): void {
    Route::apiResource('transfers', TransferController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('fx-orders', FxOrderController::class)->only(['update', 'destroy'])->parameters(['fx-orders' => 'fxOrder']);
    Route::post('transfers/{transfer}/sync-status', [TransferController::class, 'syncStatus'])->name('transfers.sync-status');
    Route::post('fx-orders/{fxOrder}/confirm', [FxOrderController::class, 'confirm'])->name('fx-orders.confirm');
    Route::post('fx-orders/{fxOrder}/reject', [FxOrderController::class, 'reject'])->name('fx-orders.reject');
});
Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->middleware('permission:transfers.approve')->name('transfers.approve');
Route::post('transfers/{transfer}/reject', [TransferController::class, 'reject'])->middleware('permission:transfers.reject')->name('transfers.reject');

Route::middleware('permission:wallet.view')->group(function (): void {
    Route::apiResource('wallets', WalletController::class)->only(['index', 'show']);
    Route::apiResource('ledger-entries', LedgerEntryController::class)->only(['index', 'show']);
});
Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show'])->middleware('permission:audit.view');

Route::middleware('permission:aml.view')->group(function (): void {
    Route::apiResource('nium-compliance-events', NiumComplianceEventController::class)->only(['index', 'show'])->parameters(['nium-compliance-events' => 'niumComplianceEvent']);
    Route::get('aml-screenings', [AmlScreeningController::class, 'index'])->name('aml-screenings.index');
    Route::get('aml-screenings/{amlScreening}', [AmlScreeningController::class, 'show'])->name('aml-screenings.show');
});
Route::middleware('permission:aml.review')->group(function (): void {
    Route::post('nium-compliance-events/{niumComplianceEvent}/review', [NiumComplianceEventController::class, 'review'])->name('nium-compliance-events.review');
    Route::post('users/{user}/kyc-profile/aml-screenings/run', [AmlScreeningController::class, 'runForUser'])->name('users.kyc-profile.aml-screenings.run');
    Route::post('aml-screenings/{amlScreening}/clear', [AmlScreeningController::class, 'clear'])->name('aml-screenings.clear');
    Route::post('aml-screenings/{amlScreening}/confirm-match', [AmlScreeningController::class, 'confirmMatch'])->name('aml-screenings.confirm-match');
});

Route::middleware('permission:rfi.view')->group(function (): void {
    Route::apiResource('nium-rfi-cases', NiumRfiCaseController::class)->only(['index', 'show'])->parameters(['nium-rfi-cases' => 'niumRfiCase']);
});
Route::middleware('permission:rfi.manage')->group(function (): void {
    Route::put('nium-rfi-cases/{niumRfiCase}/draft', [NiumRfiCaseController::class, 'draft'])->name('nium-rfi-cases.draft');
    Route::post('nium-rfi-cases/{niumRfiCase}/approve', [NiumRfiCaseController::class, 'approve'])->name('nium-rfi-cases.approve');
    Route::post('nium-rfi-cases/{niumRfiCase}/submit', [NiumRfiCaseController::class, 'submit'])->name('nium-rfi-cases.submit');
});

Route::get('kyc-submissions', [UserKycSubmissionController::class, 'index'])->middleware('permission:kyc.view')->name('kyc-submissions.index');
Route::get('kyc-profiles', [UserKycSubmissionController::class, 'index'])->middleware('permission:kyc.view')->name('kyc-profiles.index');
Route::get('users/{user}/kyc-profile', [UserKycSubmissionController::class, 'show'])->middleware('permission:kyc.view')->name('users.kyc-profile.show');
Route::post('users/{user}/kyc-profile/approve', [UserKycSubmissionController::class, 'approve'])
    ->middleware('permission:kyc.approve')
    ->name('users.kyc-profile.approve');
Route::post('users/{user}/kyc-profile/reject', [UserKycSubmissionController::class, 'reject'])
    ->middleware('permission:kyc.reject')
    ->name('users.kyc-profile.reject');
Route::post('users/{user}/kyc-profile/requirements/request-update', [UserKycSubmissionController::class, 'requestUpdate'])
    ->middleware('permission:kyc.reject')
    ->name('users.kyc-profile.requirements.request-update');
Route::get('users/{user}/kyc-submission', [UserKycSubmissionController::class, 'show'])
    ->middleware('permission:kyc.view')
    ->name('users.kyc-submission.show');
Route::post('users/{user}/kyc-submission/approve', [UserKycSubmissionController::class, 'approve'])
    ->middleware('permission:kyc.approve')
    ->name('users.kyc-submission.approve');
Route::post('users/{user}/kyc-submission/reject', [UserKycSubmissionController::class, 'reject'])
    ->middleware('permission:kyc.reject')
    ->name('users.kyc-submission.reject');
Route::post('users/{user}/kyc-submission/requirements/request-update', [UserKycSubmissionController::class, 'requestUpdate'])
    ->middleware('permission:kyc.reject')
    ->name('users.kyc-submission.requirements.request-update');

Route::get('kyc-provider-submissions', [KycProviderSubmissionController::class, 'index'])
    ->middleware('permission:kyc.view')
    ->name('kyc-provider-submissions.index');
Route::get('users/{user}/kyc-profile/provider-submissions', [KycProviderSubmissionController::class, 'userIndex'])
    ->middleware('permission:kyc.view')
    ->name('users.kyc-profile.provider-submissions.index');
Route::post('users/{user}/kyc-profile/providers/{provider}/approve', [KycProviderSubmissionController::class, 'approve'])
    ->middleware('permission:kyc.approve')
    ->name('users.kyc-profile.providers.approve');
Route::post('users/{user}/kyc-profile/providers/{provider}/reject', [KycProviderSubmissionController::class, 'reject'])
    ->middleware('permission:kyc.reject')
    ->name('users.kyc-profile.providers.reject');

Route::get('users/{user}/integration-links', [UserIntegrationLinkController::class, 'index'])
    ->middleware('permission:users.view')
    ->name('users.integration-links.index');
Route::put('users/{user}/integration-links/{provider}', [UserIntegrationLinkController::class, 'upsert'])
    ->middleware('permission:users.manage')
    ->name('users.integration-links.upsert');
Route::delete('users/{user}/integration-links/{provider}', [UserIntegrationLinkController::class, 'destroy'])
    ->middleware('permission:users.manage')
    ->name('users.integration-links.destroy');
