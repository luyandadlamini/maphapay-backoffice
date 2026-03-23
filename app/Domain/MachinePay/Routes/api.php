<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MachinePay\MppPaymentController;
use App\Http\Controllers\Api\MachinePay\MppResourceController;
use App\Http\Controllers\Api\MachinePay\MppStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Machine Payments Protocol (MPP) API Routes
|--------------------------------------------------------------------------
|
| Routes for the Machine Payments Protocol domain.
| Public endpoints: protocol status, supported rails, discovery.
| Authenticated endpoints: monetized resource CRUD, payment history,
| spending limit management.
|
*/

// Public endpoints
Route::prefix('v1/mpp')->name('api.v1.mpp.')->group(function (): void {
    Route::get('/status', [MppStatusController::class, 'status'])->name('status');
    Route::get('/supported-rails', [MppStatusController::class, 'supportedRails'])->name('supported-rails');
});

// Well-known discovery endpoint
Route::get('/.well-known/mpp-configuration', [MppStatusController::class, 'wellKnown'])
    ->name('api.mpp.well-known');

// Authenticated endpoints
Route::prefix('v1/mpp')->name('api.v1.mpp.')
    ->middleware(['auth:sanctum'])
    ->group(function (): void {
        // Monetized resource management
        Route::get('/resources', [MppResourceController::class, 'index'])->middleware('api.rate_limit:query')->name('resources.index');
        Route::post('/resources', [MppResourceController::class, 'store'])->name('resources.store');
        Route::get('/resources/{id}', [MppResourceController::class, 'show'])->middleware('api.rate_limit:query')->name('resources.show');
        Route::put('/resources/{id}', [MppResourceController::class, 'update'])->name('resources.update');
        Route::delete('/resources/{id}', [MppResourceController::class, 'destroy'])->name('resources.destroy');

        // Payment history
        Route::get('/payments', [MppPaymentController::class, 'index'])->middleware('api.rate_limit:query')->name('payments.index');
        Route::get('/payments/stats', [MppPaymentController::class, 'stats'])->middleware('api.rate_limit:query')->name('payments.stats');

        // Spending limits
        Route::get('/spending-limits', [MppPaymentController::class, 'spendingLimits'])->middleware('api.rate_limit:query')->name('spending-limits.index');
        Route::post('/spending-limits', [MppPaymentController::class, 'setSpendingLimit'])->name('spending-limits.store');
        Route::delete('/spending-limits/{agentId}', [MppPaymentController::class, 'deleteSpendingLimit'])->name('spending-limits.destroy');
    });
