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

Route::apiResources([
    'users' => UserController::class,
    'contact-submissions' => ContactSubmissionController::class,
    'integration-providers' => IntegrationProviderController::class,
    'exchange-rates' => ManagedExchangeRateController::class,
    'bank-accounts' => BankAccountController::class,
    'beneficiaries' => BeneficiaryController::class,
    'transfers' => TransferController::class,
    'transactions' => TransactionController::class,
]);

Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);
Route::apiResource('wallets', WalletController::class)->only(['index', 'show']);
Route::apiResource('ledger-entries', LedgerEntryController::class)->only(['index', 'show']);
Route::apiResource('provider-health', ProviderHealthController::class)
    ->only(['index'])
    ->parameters(['provider-health' => 'provider']);
Route::post('provider-health/{provider}/check', [ProviderHealthController::class, 'check'])
    ->name('provider-health.check');
Route::apiResource('provider-webhook-events', ProviderWebhookEventController::class)
    ->only(['index', 'show'])
    ->parameters(['provider-webhook-events' => 'providerWebhookEvent']);
Route::post('provider-webhook-events/{providerWebhookEvent}/retry', [ProviderWebhookEventController::class, 'retry'])
    ->name('provider-webhook-events.retry');

Route::apiResource('fx-orders', FxOrderController::class)
    ->except(['store'])
    ->parameters(['fx-orders' => 'fxOrder']);
Route::post('providers/{provider}/users/{user}/sync', [ProviderSyncController::class, 'syncUser'])
    ->name('providers.users.sync');
Route::post('transfers/{transfer}/sync-status', [TransferController::class, 'syncStatus'])
    ->name('transfers.sync-status');
Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])
    ->name('transfers.approve');
Route::post('transfers/{transfer}/reject', [TransferController::class, 'reject'])
    ->name('transfers.reject');
Route::post('fx-orders/{fxOrder}/confirm', [FxOrderController::class, 'confirm'])
    ->name('fx-orders.confirm');
Route::post('fx-orders/{fxOrder}/reject', [FxOrderController::class, 'reject'])
    ->name('fx-orders.reject');

Route::get('kyc-submissions', [UserKycSubmissionController::class, 'index'])
    ->name('kyc-submissions.index');
Route::get('kyc-profiles', [UserKycSubmissionController::class, 'index'])
    ->name('kyc-profiles.index');
Route::get('users/{user}/kyc-profile', [UserKycSubmissionController::class, 'show'])
    ->name('users.kyc-profile.show');
Route::post('users/{user}/kyc-profile/approve', [UserKycSubmissionController::class, 'approve'])
    ->name('users.kyc-profile.approve');
Route::post('users/{user}/kyc-profile/reject', [UserKycSubmissionController::class, 'reject'])
    ->name('users.kyc-profile.reject');
Route::post('users/{user}/kyc-profile/requirements/request-update', [UserKycSubmissionController::class, 'requestUpdate'])
    ->name('users.kyc-profile.requirements.request-update');
Route::get('users/{user}/kyc-submission', [UserKycSubmissionController::class, 'show'])
    ->name('users.kyc-submission.show');
Route::post('users/{user}/kyc-submission/approve', [UserKycSubmissionController::class, 'approve'])
    ->name('users.kyc-submission.approve');
Route::post('users/{user}/kyc-submission/reject', [UserKycSubmissionController::class, 'reject'])
    ->name('users.kyc-submission.reject');
Route::post('users/{user}/kyc-submission/requirements/request-update', [UserKycSubmissionController::class, 'requestUpdate'])
    ->name('users.kyc-submission.requirements.request-update');

Route::get('kyc-provider-submissions', [KycProviderSubmissionController::class, 'index'])
    ->name('kyc-provider-submissions.index');
Route::get('users/{user}/kyc-profile/provider-submissions', [KycProviderSubmissionController::class, 'userIndex'])
    ->name('users.kyc-profile.provider-submissions.index');
Route::post('users/{user}/kyc-profile/providers/{provider}/approve', [KycProviderSubmissionController::class, 'approve'])
    ->name('users.kyc-profile.providers.approve');
Route::post('users/{user}/kyc-profile/providers/{provider}/reject', [KycProviderSubmissionController::class, 'reject'])
    ->name('users.kyc-profile.providers.reject');

Route::get('aml-screenings', [AmlScreeningController::class, 'index'])
    ->name('aml-screenings.index');
Route::get('aml-screenings/{amlScreening}', [AmlScreeningController::class, 'show'])
    ->name('aml-screenings.show');
Route::post('users/{user}/kyc-profile/aml-screenings/run', [AmlScreeningController::class, 'runForUser'])
    ->name('users.kyc-profile.aml-screenings.run');
Route::post('aml-screenings/{amlScreening}/clear', [AmlScreeningController::class, 'clear'])
    ->name('aml-screenings.clear');
Route::post('aml-screenings/{amlScreening}/confirm-match', [AmlScreeningController::class, 'confirmMatch'])
    ->name('aml-screenings.confirm-match');

Route::get('users/{user}/integration-links', [UserIntegrationLinkController::class, 'index'])
    ->name('users.integration-links.index');
Route::put('users/{user}/integration-links/{provider}', [UserIntegrationLinkController::class, 'upsert'])
    ->name('users.integration-links.upsert');
Route::delete('users/{user}/integration-links/{provider}', [UserIntegrationLinkController::class, 'destroy'])
    ->name('users.integration-links.destroy');
