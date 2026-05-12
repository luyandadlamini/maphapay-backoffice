<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CardIssuance\CardController;
use App\Http\Controllers\Api\CardIssuance\CardholderController;
use App\Http\Controllers\Api\CardIssuance\CardTransactionWebhookController;
use App\Http\Controllers\Api\CardIssuance\JitFundingWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/cards')->name('api.cards.')->group(function () {
    // Card monetisation owns the mobile-facing /v1/cards contract in
    // app/Domain/CardSubscriptions/Routes/api.php. Keep only the legacy
    // provisioning endpoint here to avoid duplicate route ownership.
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/provision', [CardController::class, 'provision'])
            ->middleware('transaction.rate_limit:card_provision')
            ->name('provision');
    });
});

// Cardholder management
Route::prefix('v1/cardholders')->name('api.cardholders.')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [CardholderController::class, 'index'])->name('index');
    Route::post('/', [CardholderController::class, 'store'])->name('store');
    Route::get('/{id}', [CardholderController::class, 'show'])->name('show');
});

// Card issuer webhook endpoints (CRITICAL: <2000ms latency budget)
Route::prefix('webhooks/card-issuer')->name('api.webhooks.card.')
    ->middleware(['api.rate_limit:webhook', 'webhook.signature:demo'])
    ->group(function () {
        Route::post('/authorization', [JitFundingWebhookController::class, 'handleAuthorization'])->name('authorization');
        Route::post('/settlement', [JitFundingWebhookController::class, 'settlement'])->name('settlement');
        Route::post('/transaction', [CardTransactionWebhookController::class, 'handleTransaction'])->name('transaction');
    });
