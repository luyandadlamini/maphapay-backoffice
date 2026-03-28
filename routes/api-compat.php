<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyHistoryController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyReceivedHistoryController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyReceivedStoreController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyRejectController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyStoreController;
use App\Http\Controllers\Api\Compatibility\SendMoney\SendMoneyStoreController;
use App\Http\Controllers\Api\Compatibility\VerificationProcess\VerifyOtpController;
use App\Http\Controllers\Api\Compatibility\VerificationProcess\VerifyPinController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MaphaPay mobile compatibility routes (Phase 18)
|--------------------------------------------------------------------------
|
| Loaded from bootstrap/app.php with prefix /api on the primary host, or without
| prefix on api.* subdomain — same pattern as routes/api.php.
|
*/

Route::middleware('migration_flag:enable_verification')->group(function () {
    Route::post('verification-process/verify/otp', VerifyOtpController::class)
        ->name('maphapay.compat.verification.otp');

    Route::post('verification-process/verify/pin', VerifyPinController::class)
        ->name('maphapay.compat.verification.pin');
});

Route::middleware(['migration_flag:enable_send_money', 'idempotency'])
    ->post('send-money/store', SendMoneyStoreController::class)
    ->name('maphapay.compat.send-money.store');

Route::middleware('migration_flag:enable_request_money')->group(function () {
    Route::post('request-money/store', RequestMoneyStoreController::class)
        ->name('maphapay.compat.request-money.store');

    Route::post('request-money/received-store/{moneyRequest}', RequestMoneyReceivedStoreController::class)
        ->middleware('idempotency')
        ->name('maphapay.compat.request-money.received-store');

    Route::post('request-money/reject/{moneyRequest}', RequestMoneyRejectController::class)
        ->name('maphapay.compat.request-money.reject');

    Route::get('request-money/history', RequestMoneyHistoryController::class)
        ->name('maphapay.compat.request-money.history');

    Route::get('request-money/received-history', RequestMoneyReceivedHistoryController::class)
        ->name('maphapay.compat.request-money.received-history');
});
