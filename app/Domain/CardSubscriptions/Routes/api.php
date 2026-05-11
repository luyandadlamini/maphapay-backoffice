<?php

declare(strict_types=1);

// SPDX-License-Identifier: Apache-2.0

use App\Domain\CardSubscriptions\Http\Controllers\CardController;
use App\Domain\CardSubscriptions\Http\Controllers\CardFeeController;
use App\Domain\CardSubscriptions\Http\Controllers\CardSubscriptionController;
use App\Domain\CardSubscriptions\Http\Controllers\CardTransactionController;
use App\Domain\CardSubscriptions\Http\Controllers\CardWebhookController;
use App\Domain\CardSubscriptions\Http\Controllers\MinorCardRequestController;
use App\Domain\CardSubscriptions\Http\Controllers\PhysicalCardOrderController;
use Illuminate\Support\Facades\Route;

// Domain: Card Subscriptions and Cards
Route::prefix('v1')->name('api.v1.')->group(function () {

    // Authenticated endpoints
    Route::middleware(['auth:sanctum', 'account.context', 'kyc_approved'])->group(function () {
        
        // --- Subscriptions ---
        Route::prefix('card-subscriptions')->name('card-subscriptions.')->group(function () {
            Route::get('/plans', [CardSubscriptionController::class, 'plans'])->name('plans');
            Route::get('/me', [CardSubscriptionController::class, 'me'])->name('me');
            
            Route::middleware(['idempotency', 'throttle:maphapay-card-subscription'])->group(function () {
                Route::post('/', [CardSubscriptionController::class, 'store'])->name('store');
                Route::post('/upgrade', [CardSubscriptionController::class, 'upgrade'])->name('upgrade');
                Route::post('/downgrade', [CardSubscriptionController::class, 'downgrade'])->name('downgrade');
                Route::post('/cancel', [CardSubscriptionController::class, 'cancel'])->name('cancel');
                Route::post('/retry-payment', [CardSubscriptionController::class, 'retryPayment'])->name('retry-payment');
            });
        });

        // --- Cards ---
        Route::prefix('cards')->name('cards.')->group(function () {
            Route::get('/', [CardController::class, 'index'])->name('index');
            
            // Physical Cards (must be before /{id} to avoid conflicts)
            Route::prefix('physical')->name('physical.')->group(function () {
                Route::get('/orders', [PhysicalCardOrderController::class, 'index'])->name('orders.index');
                Route::get('/orders/{id}', [PhysicalCardOrderController::class, 'show'])->name('orders.show');
                
                Route::middleware(['idempotency', 'throttle:maphapay-card-creation'])->group(function () {
                    Route::post('/request', [PhysicalCardOrderController::class, 'store'])->name('request');
                });
                
                Route::middleware(['idempotency', 'throttle:maphapay-card-mutation'])->group(function () {
                    Route::post('/orders/{id}/activate', [PhysicalCardOrderController::class, 'activate'])->name('orders.activate');
                    Route::post('/orders/{id}/cancel', [PhysicalCardOrderController::class, 'cancel'])->name('orders.cancel');
                });
            });

            Route::get('/{id}', [CardController::class, 'show'])->name('show');
            Route::get('/{id}/transactions', [CardTransactionController::class, 'index'])->name('transactions.index');
            Route::get('/{id}/reveal', [CardController::class, 'reveal'])->middleware('throttle:maphapay-card-reveal')->name('reveal');
            Route::get('/{id}/reveal/secure', function() { return response()->json(['secure' => true]); })->name('reveal.show-secure');
            
            Route::middleware(['idempotency', 'throttle:maphapay-card-creation'])->group(function () {
                Route::post('/virtual', [CardController::class, 'storeVirtual'])->name('virtual.store');
                Route::post('/{id}/replace', [CardController::class, 'replace'])->name('replace');
            });

            Route::middleware(['idempotency', 'throttle:maphapay-card-mutation'])->group(function () {
                Route::post('/{id}/freeze', [CardController::class, 'freeze'])->name('freeze');
                Route::post('/{id}/unfreeze', [CardController::class, 'unfreeze'])->name('unfreeze');
                Route::post('/{id}/cancel', [CardController::class, 'cancel'])->name('cancel');
                Route::patch('/{id}/controls', [CardController::class, 'updateControls'])->name('controls.update');
            });
        });

        // --- Card Transactions ---
        Route::prefix('card-transactions')->name('card-transactions.')->group(function () {
            Route::get('/{id}', [CardTransactionController::class, 'show'])->name('show');
            Route::middleware(['idempotency', 'throttle:maphapay-card-mutation'])->group(function () {
                Route::post('/{id}/dispute', [CardTransactionController::class, 'dispute'])->name('dispute');
            });
        });

        // --- Card Fees ---
        Route::prefix('card-fees')->name('card-fees.')->group(function () {
            Route::post('/preview', [CardFeeController::class, 'preview'])->name('preview');
        });

        // --- Minor Card Requests ---
        Route::prefix('minor-card-requests')->name('minor-card-requests.')->group(function () {
            Route::middleware(['idempotency', 'throttle:maphapay-card-mutation'])->group(function () {
                Route::post('/{id}/approve', [MinorCardRequestController::class, 'approve'])->name('approve');
                Route::post('/{id}/deny', [MinorCardRequestController::class, 'deny'])->name('deny');
            });
        });

    });

});

// --- Webhooks ---
Route::prefix('webhooks/cards')->name('api.webhooks.cards.')->group(function () {
    Route::middleware(['throttle:maphapay-card-webhook'])->group(function () {
        Route::post('/{processor}/{eventType}', [CardWebhookController::class, 'handle'])
            ->whereIn('eventType', ['authorisation', 'clearing', 'reversal', 'refund'])
            ->name('handle');
    });
});
