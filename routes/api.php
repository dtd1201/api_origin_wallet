<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ProviderWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
    return response()->json([
        'message' => 'API working',
    ]);
});

Route::get('/providers', [ProviderController::class, 'index']);

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('register/verify', [AuthController::class, 'verifyRegistration']);
    Route::get('register/activate', [AuthController::class, 'activateRegistration'])
        ->name('auth.register.activate');
    Route::post('login', [AuthController::class, 'login']);
    Route::post('login/verify', [AuthController::class, 'verifyLogin']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('google', [AuthController::class, 'googleLogin']);

    Route::middleware('auth.token')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
    });
});

Route::post('webhooks/providers/{provider}', ProviderWebhookController::class)
    ->name('webhooks.providers.handle');

Route::prefix('user')
    ->as('user.')
    ->middleware(['auth.token', 'auth.user'])
    ->group(base_path('routes/user.php'));

Route::prefix('admin')
    ->as('admin.')
    ->middleware(['auth.token', 'auth.admin'])
    ->group(base_path('routes/admin.php'));
