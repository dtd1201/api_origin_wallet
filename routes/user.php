<?php

use App\Http\Controllers\Api\User\BalanceController;
use App\Http\Controllers\Api\User\BankAccountController;
use App\Http\Controllers\Api\User\BeneficiaryController;
use App\Http\Controllers\Api\User\FxQuoteController;
use App\Http\Controllers\Api\User\KycSubmissionController;
use App\Http\Controllers\Api\User\OverviewController;
use App\Http\Controllers\Api\User\ProfileController;
use App\Http\Controllers\Api\User\ProviderAccountController;
use App\Http\Controllers\Api\User\ProviderDataSyncController;
use App\Http\Controllers\Api\User\TransactionController;
use App\Http\Controllers\Api\User\TransferController;
use Illuminate\Support\Facades\Route;

Route::get('users/{user}/overview', [OverviewController::class, 'show']);
Route::get('users/{user}/profile', [ProfileController::class, 'show']);
Route::put('users/{user}/profile', [ProfileController::class, 'update']);
Route::get('users/{user}/kyc-profile', [KycSubmissionController::class, 'show']);
Route::put('users/{user}/kyc-profile', [KycSubmissionController::class, 'submit']);
Route::get('users/{user}/kyc-submission', [KycSubmissionController::class, 'show']);
Route::put('users/{user}/kyc-submission', [KycSubmissionController::class, 'submit']);

Route::middleware('profile.complete')->group(function (): void {
    Route::get('users/{user}/provider-accounts', [ProviderAccountController::class, 'index']);
    Route::get('users/{user}/provider-accounts/{provider}', [ProviderAccountController::class, 'show']);
    Route::post('users/{user}/provider-accounts/{provider}/link', [ProviderAccountController::class, 'link']);
    Route::post('users/{user}/provider-accounts/{provider}/complete', [ProviderAccountController::class, 'complete']);
    Route::post('users/{user}/provider-accounts/{provider}/request-connect', [ProviderAccountController::class, 'requestConnect']);
    Route::post('users/{user}/providers/{provider}/sync/accounts', [ProviderDataSyncController::class, 'syncAccounts']);
    Route::post('users/{user}/providers/{provider}/sync/balances', [ProviderDataSyncController::class, 'syncBalances']);
    Route::post('users/{user}/providers/{provider}/sync/transactions', [ProviderDataSyncController::class, 'syncTransactions']);

    Route::post('users/{user}/fx-quotes', [FxQuoteController::class, 'store']);
    Route::get('users/{user}/fx-quotes', [FxQuoteController::class, 'index']);
    Route::get('users/{user}/fx-quotes/{fxQuote}', [FxQuoteController::class, 'show']);

    Route::get('users/{user}/balances', [BalanceController::class, 'index']);
    Route::get('users/{user}/bank-accounts', [BankAccountController::class, 'index']);
    Route::get('users/{user}/bank-accounts/{bankAccount}', [BankAccountController::class, 'show']);

    Route::post('users/{user}/beneficiaries', [BeneficiaryController::class, 'store']);
    Route::get('users/{user}/beneficiaries', [BeneficiaryController::class, 'index']);
    Route::put('users/{user}/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update']);
    Route::delete('users/{user}/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'destroy']);
    Route::get('users/{user}/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'show']);

    Route::post('users/{user}/transfers', [TransferController::class, 'store']);
    Route::get('users/{user}/transfers', [TransferController::class, 'index']);
    Route::post('users/{user}/transfers/{transfer}/submit', [TransferController::class, 'submit']);
    Route::post('users/{user}/transfers/{transfer}/sync-status', [TransferController::class, 'syncStatus']);
    Route::post('users/{user}/transfers/{transfer}/cancel', [TransferController::class, 'cancel']);
    Route::get('users/{user}/transfers/{transfer}', [TransferController::class, 'show']);

    Route::get('users/{user}/transactions', [TransactionController::class, 'index']);
    Route::get('users/{user}/transactions/{transaction}', [TransactionController::class, 'show']);
});
