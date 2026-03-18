<?php

use App\Http\Controllers\Api\Admin\BankAccountController;
use App\Http\Controllers\Api\Admin\BeneficiaryController;
use App\Http\Controllers\Api\Admin\IntegrationProviderController;
use App\Http\Controllers\Api\Admin\ProviderSyncController;
use App\Http\Controllers\Api\Admin\TransactionController;
use App\Http\Controllers\Api\Admin\TransferController;
use App\Http\Controllers\Api\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::apiResources([
    'users' => UserController::class,
    'integration-providers' => IntegrationProviderController::class,
    'bank-accounts' => BankAccountController::class,
    'beneficiaries' => BeneficiaryController::class,
    'transfers' => TransferController::class,
    'transactions' => TransactionController::class,
]);

Route::post('providers/{provider}/users/{user}/sync', [ProviderSyncController::class, 'syncUser'])
    ->name('providers.users.sync');
