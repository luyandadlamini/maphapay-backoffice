<?php

declare(strict_types=1);

use App\Http\Controllers\Mock\Wallets\AdminBalanceController;
use App\Http\Controllers\Mock\Wallets\AdminFundController;
use App\Http\Controllers\Mock\Wallets\MtnMomo\CollectionController;
use App\Http\Controllers\Mock\Wallets\MtnMomo\DisbursementController;
use App\Http\Controllers\Mock\Wallets\MtnMomo\TokenController;
use Illuminate\Support\Facades\Route;

if (app()->environment('production') && (bool) config('wallet_mocks.enabled')) {
    throw new RuntimeException('Wallet mocks cannot be enabled in production.');
}

Route::prefix('__mock/wallets')->middleware(['api'])->group(function (): void {
    Route::post('{provider}/_admin/fund', AdminFundController::class);
    Route::get('{provider}/_admin/balance/{accountRef}', AdminBalanceController::class);

    Route::prefix('mtn-momo')->group(function (): void {
        Route::post('collection/token/', [TokenController::class, 'collection']);
        Route::post('disbursement/token/', [TokenController::class, 'disbursement']);
        Route::post('collection/v1_0/requesttopay', [CollectionController::class, 'create']);
        Route::get('collection/v1_0/requesttopay/{ref}', [CollectionController::class, 'show']);
        Route::post('disbursement/v1_0/transfer', [DisbursementController::class, 'create']);
        Route::get('disbursement/v1_0/transfer/{ref}', [DisbursementController::class, 'show']);
    });
});
