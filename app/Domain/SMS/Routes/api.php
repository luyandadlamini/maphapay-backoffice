<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SMS\SmsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SMS Domain Routes
|--------------------------------------------------------------------------
|
| POST /v1/sms/send       — MPP-gated + rate-limited: send SMS
| GET  /v1/sms/rates      — public: check rates by country
| GET  /v1/sms/info       — public: service status
| GET  /v1/sms/status/{id} — auth: check delivery status
|
*/

Route::prefix('v1/sms')->group(function (): void {
    Route::get('info', [SmsController::class, 'info']);
    Route::get('rates', [SmsController::class, 'rates']);

    Route::post('send', [SmsController::class, 'send'])
        ->middleware(['mpp.payment', 'throttle:60,1']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('status/{messageId}', [SmsController::class, 'status']);
    });
});
