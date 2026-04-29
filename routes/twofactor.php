<?php

use App\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/two-factor/setup', [TwoFactorController::class, 'create'])
        ->name('two-factor.setup');

    Route::post('/two-factor/setup', [TwoFactorController::class, 'store'])
        ->middleware('throttle:totp-setup')
        ->name('two-factor.setup.store');

    Route::get('/two-factor/verify', [TwoFactorController::class, 'showVerify'])
        ->name('two-factor.verify');

    Route::post('/two-factor/verify', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:totp-verify')
        ->name('two-factor.verify.store');

    Route::middleware('twofactor')->group(function () {
        Route::post('/two-factor/reset', [TwoFactorController::class, 'initiateReset'])
            ->middleware('throttle:totp-verify')
            ->name('two-factor.reset');

        Route::get('/two-factor/recovery-codes', [TwoFactorController::class, 'showRecoveryCodes'])
            ->name('two-factor.recovery-codes');

        Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:totp-verify')
            ->name('two-factor.recovery-codes.regenerate');

        Route::delete('/two-factor', [TwoFactorController::class, 'destroy'])
            ->middleware('throttle:totp-verify')
            ->name('two-factor.destroy');
    });
});
