<?php

use App\Http\Controllers\Api\User\BalanceController;
use App\Http\Controllers\Api\User\BankAccountController;
use App\Http\Controllers\Api\User\BeneficiaryController;
use App\Http\Controllers\Api\User\BusinessRegistryVerificationController;
use App\Http\Controllers\Api\User\FxOrderController;
use App\Http\Controllers\Api\User\FxQuoteController;
use App\Http\Controllers\Api\User\IdentityVerificationController;
use App\Http\Controllers\Api\User\KycApplicantCorrectionController;
use App\Http\Controllers\Api\User\KycSubmissionController;
use App\Http\Controllers\Api\User\NiumCorporateConstantsController;
use App\Http\Controllers\Api\User\NiumPurposeCodeController;
use App\Http\Controllers\Api\User\NiumRfiCaseController;
use App\Http\Controllers\Api\User\NiumSgdLocalPayoutController;
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
Route::get('users/{user}/kyc-profile/applicant-correction', [KycApplicantCorrectionController::class, 'show']);
Route::post('users/{user}/kyc-profile/applicant-correction', [KycApplicantCorrectionController::class, 'store']);
Route::post('users/{user}/kyc-profile/applicant-correction/{correction}/proof-of-address', [KycApplicantCorrectionController::class, 'uploadProofOfAddress']);
Route::post('users/{user}/kyc-profile/documents', [KycSubmissionController::class, 'uploadDocument']);
Route::get('users/{user}/nium-rfi-cases', [NiumRfiCaseController::class, 'index']);
Route::post('users/{user}/nium-rfi-documents', [NiumRfiCaseController::class, 'upload']);
Route::put('users/{user}/nium-rfi-cases/{niumRfiCase}/draft', [NiumRfiCaseController::class, 'draft']);
Route::post('users/{user}/nium-rfi-cases/{niumRfiCase}/submit', [NiumRfiCaseController::class, 'submit']);
Route::post('users/{user}/kyc-profile/requirements/{requirement}/resubmit', [KycSubmissionController::class, 'resubmitRequirement']);
Route::post('users/{user}/kyc-profile/business-registry/verify', BusinessRegistryVerificationController::class);
Route::get('users/{user}/kyc-profile/corporate-constants', NiumCorporateConstantsController::class);
Route::get('users/{user}/kyc-submission', [KycSubmissionController::class, 'show']);
Route::put('users/{user}/kyc-submission', [KycSubmissionController::class, 'submit']);
Route::post('users/{user}/identity-verification-sessions', [IdentityVerificationController::class, 'start']);
Route::post('users/{user}/identity-verification-sessions/{identityVerificationSession}/uploads', [IdentityVerificationController::class, 'upload']);
Route::post('users/{user}/identity-verification-sessions/{identityVerificationSession}/complete', [IdentityVerificationController::class, 'complete']);

Route::middleware('profile.complete')->group(function (): void {
    Route::get('users/{user}/provider-accounts', [ProviderAccountController::class, 'index']);
    Route::get('users/{user}/provider-accounts/{provider}', [ProviderAccountController::class, 'show']);
    Route::get('users/{user}/provider-accounts/{provider}/virtual-accounts', [ProviderAccountController::class, 'virtualAccounts']);
    Route::post('users/{user}/provider-accounts/{provider}/link', [ProviderAccountController::class, 'link']);
    Route::post('users/{user}/provider-accounts/{provider}/complete', [ProviderAccountController::class, 'complete']);
    Route::post('users/{user}/provider-accounts/{provider}/request-connect', [ProviderAccountController::class, 'requestConnect']);
    Route::post('users/{user}/provider-accounts/{provider}/virtual-account', [ProviderAccountController::class, 'assignVirtualAccount']);
    Route::post('users/{user}/providers/{provider}/sync/accounts', [ProviderDataSyncController::class, 'syncAccounts']);
    Route::post('users/{user}/providers/{provider}/sync/balances', [ProviderDataSyncController::class, 'syncBalances']);
    Route::post('users/{user}/providers/{provider}/sync/transactions', [ProviderDataSyncController::class, 'syncTransactions']);

    Route::post('users/{user}/fx-quotes', [FxQuoteController::class, 'store']);
    Route::get('users/{user}/fx-quotes', [FxQuoteController::class, 'index']);
    Route::get('users/{user}/fx-quotes/{fxQuote}', [FxQuoteController::class, 'show']);
    Route::post('users/{user}/fx-orders', [FxOrderController::class, 'store']);
    Route::get('users/{user}/fx-orders', [FxOrderController::class, 'index']);
    Route::get('users/{user}/fx-orders/{fxOrder}', [FxOrderController::class, 'show']);

    Route::get('users/{user}/balances', [BalanceController::class, 'index']);
    Route::get('users/{user}/bank-accounts', [BankAccountController::class, 'index']);
    Route::get('users/{user}/bank-accounts/{bankAccount}', [BankAccountController::class, 'show']);

    Route::get('users/{user}/beneficiaries/requirements', [BeneficiaryController::class, 'requirements']);
    Route::get('users/{user}/beneficiaries/payout-methods', [BeneficiaryController::class, 'payoutMethods']);
    Route::post('users/{user}/beneficiaries', [BeneficiaryController::class, 'store']);
    Route::get('users/{user}/beneficiaries', [BeneficiaryController::class, 'index']);
    Route::put('users/{user}/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update']);
    Route::delete('users/{user}/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'destroy']);
    Route::get('users/{user}/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'show']);

    Route::post('users/{user}/transfers', [TransferController::class, 'store']);
    Route::post('users/{user}/sgd-local-payouts', [NiumSgdLocalPayoutController::class, 'store'])->middleware('throttle:6,1');
    Route::post('users/{user}/transfers/{transfer}/sgd-local-fx-lock', [NiumSgdLocalPayoutController::class, 'lock'])->middleware('throttle:6,1');
    Route::post('users/{user}/transfers/{transfer}/sgd-local-refresh-pricing', [NiumSgdLocalPayoutController::class, 'refreshPricing'])->middleware('throttle:6,1');
    Route::post('users/{user}/transfers/{transfer}/sgd-local-request-approval', [NiumSgdLocalPayoutController::class, 'requestApproval']);
    Route::post('users/{user}/transfers/{transfer}/sgd-local-recovery-review', [NiumSgdLocalPayoutController::class, 'recoveryReview']);
    Route::post('users/{user}/transfers/{transfer}/sgd-local-restart-review', [NiumSgdLocalPayoutController::class, 'restartReview']);
    Route::post('users/{user}/transfers/{transfer}/sgd-local-recover-captured', [NiumSgdLocalPayoutController::class, 'recoverCaptured']);
    Route::get('users/{user}/transfers', [TransferController::class, 'index']);
    Route::get('users/{user}/transfers/nium-purpose-codes', NiumPurposeCodeController::class);
    Route::post('users/{user}/transfers/{transfer}/submit', [TransferController::class, 'submit']);
    Route::post('users/{user}/transfers/{transfer}/sync-status', [TransferController::class, 'syncStatus']);
    Route::post('users/{user}/transfers/{transfer}/cancel', [TransferController::class, 'cancel']);
    Route::get('users/{user}/transfers/{transfer}', [TransferController::class, 'show']);

    Route::get('users/{user}/transactions', [TransactionController::class, 'index']);
    Route::get('users/{user}/transactions/{transaction}', [TransactionController::class, 'show']);
});
