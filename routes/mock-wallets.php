<?php

declare(strict_types=1);

use App\Http\Controllers\Mock\Wallets\AdminBalanceController;
use App\Http\Controllers\Mock\Wallets\AdminFundController;
use App\Http\Controllers\Mock\Wallets\Emali\CollectionController as EmaliCollectionController;
use App\Http\Controllers\Mock\Wallets\Emali\DisbursementController as EmaliDisbursementController;
use App\Http\Controllers\Mock\Wallets\Emali\TokenController as EmaliTokenController;
use App\Http\Controllers\Mock\Wallets\FnbEwallet\CreditController as FnbCreditController;
use App\Http\Controllers\Mock\Wallets\FnbEwallet\TokenController as FnbTokenController;
use App\Http\Controllers\Mock\Wallets\FnbEwallet\TransferController as FnbTransferController;
use App\Http\Controllers\Mock\Wallets\MtnMomo\CollectionController;
use App\Http\Controllers\Mock\Wallets\MtnMomo\DisbursementController;
use App\Http\Controllers\Mock\Wallets\MtnMomo\TokenController;
use App\Http\Controllers\Mock\Wallets\NedbankSendMoney\InboundController as NedbankInboundController;
use App\Http\Controllers\Mock\Wallets\NedbankSendMoney\OutboundController as NedbankOutboundController;
use App\Http\Controllers\Mock\Wallets\NedbankSendMoney\TokenController as NedbankTokenController;
use App\Http\Controllers\Mock\Wallets\StandardUnayo\CashInController as UnayoCashInController;
use App\Http\Controllers\Mock\Wallets\StandardUnayo\CashOutController as UnayoCashOutController;
use App\Http\Controllers\Mock\Wallets\StandardUnayo\TokenController as UnayoTokenController;
use Illuminate\Support\Facades\Route;

if (
    app()->environment('production')
    && (bool) config('wallet_mocks.enabled')
    && ! (bool) config('wallet_mocks.allow_in_production')
) {
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

    Route::prefix('emali')->group(function (): void {
        Route::post('v1/auth/token', EmaliTokenController::class);
        Route::post('v1/collections', [EmaliCollectionController::class, 'create']);
        Route::get('v1/collections/{ref}', [EmaliCollectionController::class, 'show']);
        Route::post('v1/disbursements', [EmaliDisbursementController::class, 'create']);
        Route::get('v1/disbursements/{ref}', [EmaliDisbursementController::class, 'show']);
    });

    Route::prefix('fnb-ewallet')->group(function (): void {
        Route::post('oauth/v2/token', FnbTokenController::class);
        Route::post('wallets/v1/credits', [FnbCreditController::class, 'create']);
        Route::get('wallets/v1/credits/{ref}', [FnbCreditController::class, 'show']);
        Route::post('wallets/v1/transfers', [FnbTransferController::class, 'create']);
        Route::get('wallets/v1/transfers/{ref}', [FnbTransferController::class, 'show']);
    });

    Route::prefix('standard-unayo')->group(function (): void {
        Route::post('oauth/token', UnayoTokenController::class);
        Route::post('unayo/v1/cashin', [UnayoCashInController::class, 'create']);
        Route::get('unayo/v1/cashin/{ref}', [UnayoCashInController::class, 'show']);
        Route::post('unayo/v1/cashout', [UnayoCashOutController::class, 'create']);
        Route::get('unayo/v1/cashout/{ref}', [UnayoCashOutController::class, 'show']);
    });

    Route::prefix('nedbank-send-money')->group(function (): void {
        Route::post('oauth2/token', NedbankTokenController::class);
        Route::post('sendmoney/v1/payments/inbound', [NedbankInboundController::class, 'create']);
        Route::get('sendmoney/v1/payments/inbound/{ref}', [NedbankInboundController::class, 'show']);
        Route::post('sendmoney/v1/payments/outbound', [NedbankOutboundController::class, 'create']);
        Route::get('sendmoney/v1/payments/outbound/{ref}', [NedbankOutboundController::class, 'show']);
    });
});
