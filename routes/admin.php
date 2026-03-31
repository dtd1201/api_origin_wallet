<?php

use App\Http\Controllers\Api\Admin\BankAccountController;
use App\Http\Controllers\Api\Admin\BeneficiaryController;
use App\Http\Controllers\Api\Admin\ContactSubmissionController;
use App\Http\Controllers\Api\Admin\IntegrationProviderController;
use App\Http\Controllers\Api\Admin\ProviderSyncController;
use App\Http\Controllers\Api\Admin\TransactionController;
use App\Http\Controllers\Api\Admin\TransferController;
use App\Http\Controllers\Api\Admin\UserIntegrationLinkController;
use App\Http\Controllers\Api\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::apiResources([
    'users' => UserController::class,
    'contact-submissions' => ContactSubmissionController::class,
    'integration-providers' => IntegrationProviderController::class,
    'bank-accounts' => BankAccountController::class,
    'beneficiaries' => BeneficiaryController::class,
    'transfers' => TransferController::class,
    'transactions' => TransactionController::class,
]);

Route::post('providers/{provider}/users/{user}/sync', [ProviderSyncController::class, 'syncUser'])
    ->name('providers.users.sync');
Route::post('transfers/{transfer}/sync-status', [TransferController::class, 'syncStatus'])
    ->name('transfers.sync-status');

Route::get('users/{user}/integration-links', [UserIntegrationLinkController::class, 'index'])
    ->name('users.integration-links.index');
Route::put('users/{user}/integration-links/{provider}', [UserIntegrationLinkController::class, 'upsert'])
    ->name('users.integration-links.upsert');
Route::delete('users/{user}/integration-links/{provider}', [UserIntegrationLinkController::class, 'destroy'])
    ->name('users.integration-links.destroy');
