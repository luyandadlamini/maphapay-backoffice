<?php

declare(strict_types=1);

use App\Http\Controllers\API\Compatibility\Budget\BudgetCategoriesController;
use App\Http\Controllers\API\Compatibility\Budget\BudgetCategoriesStoreController;
use App\Http\Controllers\API\Compatibility\Budget\BudgetCategoriesUpdateController;
use App\Http\Controllers\API\Compatibility\Budget\BudgetCategoriesDeleteController;
use App\Http\Controllers\API\Compatibility\Budget\BudgetController;
use App\Http\Controllers\API\Compatibility\Budget\BudgetUpdateController;
use App\Http\Controllers\API\Compatibility\Kyc\KycFormController;
use App\Http\Controllers\API\Compatibility\Kyc\KycSubmitController;
use App\Http\Controllers\API\Compatibility\Pockets\PocketsController;
use App\Http\Controllers\API\Compatibility\Pockets\PocketsStoreController;
use App\Http\Controllers\API\Compatibility\Pockets\PocketsUpdateController;
use App\Http\Controllers\API\Compatibility\Pockets\PocketsAddFundsController;
use App\Http\Controllers\API\Compatibility\Pockets\PocketsWithdrawFundsController;
use App\Http\Controllers\API\Compatibility\Pockets\PocketsUpdateRulesController;
use App\Http\Controllers\API\Compatibility\Rewards\RewardsController;
use App\Http\Controllers\API\Compatibility\Rewards\RewardsPointsController;
use App\Http\Controllers\API\Compatibility\SocialMoney\SocialFriendsController;
use App\Http\Controllers\API\Compatibility\SocialMoney\SocialChatCompatController;
use App\Http\Controllers\API\Compatibility\SocialMoney\SocialFriendRequestsController;
use App\Http\Controllers\API\Compatibility\SocialMoney\SocialFriendshipStatusController;
use App\Http\Controllers\API\Compatibility\SocialMoney\SocialSummaryController;
use App\Http\Controllers\API\Compatibility\SocialMoney\SocialFriendRequestStoreController;
use App\Http\Controllers\API\Compatibility\SocialMoney\SocialUserLookupController;
use App\Http\Controllers\API\Compatibility\WalletLinking\WalletLinkingController;
use App\Http\Controllers\API\Compatibility\Dashboard\DashboardController;
use App\Http\Controllers\API\Compatibility\Mtn\CallbackController;
use App\Http\Controllers\API\Compatibility\Mtn\DisbursementController;
use App\Http\Controllers\API\Compatibility\Mtn\RequestToPayController;
use App\Http\Controllers\API\Compatibility\Mtn\TransactionStatusController;
use App\Http\Controllers\API\Compatibility\RequestMoney\RequestMoneyHistoryController;
use App\Http\Controllers\API\Compatibility\SocialMoney\SocialThreadsController;
use App\Http\Controllers\API\Compatibility\RequestMoney\RequestMoneyReceivedHistoryController;
use App\Http\Controllers\API\Compatibility\RequestMoney\RequestMoneyReceivedStoreController;
use App\Http\Controllers\API\Compatibility\RequestMoney\RequestMoneyRejectController;
use App\Http\Controllers\API\Compatibility\RequestMoney\RequestMoneyStoreController;
use App\Http\Controllers\API\Compatibility\ScheduledSend\ScheduledSendCancelController;
use App\Http\Controllers\API\Compatibility\ScheduledSend\ScheduledSendIndexController;
use App\Http\Controllers\API\Compatibility\ScheduledSend\ScheduledSendStoreController;
use App\Http\Controllers\API\Compatibility\SendMoney\SendMoneyStoreController;
use App\Http\Controllers\API\Compatibility\Transactions\TransactionHistoryController;
use App\Http\Controllers\API\Compatibility\VerificationProcess\VerifyOtpController;
use App\Http\Controllers\API\Compatibility\VerificationProcess\VerifyPinController;
use App\Http\Controllers\API\Compatibility\VirtualCard\VirtualCardListController;
use App\Http\Controllers\API\Compatibility\VirtualCard\VirtualCardViewController;
use App\Http\Controllers\API\Compatibility\VirtualCard\VirtualCardEnsureDefaultController;
use App\Http\Controllers\API\Compatibility\VirtualCard\VirtualCardStoreAdditionalController;
use App\Http\Controllers\API\Compatibility\VirtualCard\VirtualCardAddFundController;
use App\Http\Controllers\API\Compatibility\VirtualCard\VirtualCardCancelController;
use App\Http\Controllers\API\Compatibility\VirtualCard\VirtualCardTransactionController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MaphaPay mobile compatibility routes (Phase 18)
|--------------------------------------------------------------------------
|
| Loaded from bootstrap/app.php with prefix /api on the primary host, or without
| prefix on api.* subdomain — same pattern as routes/api.php.
|
| Phase 0 money-movement contract freeze:
| - compat initiation routes accept string major-unit `amount`, explicit `note`,
|   optional `asset_code`, and a required Idempotency-Key header.
| - compat verification routes must fail closed: only `status = success` is a
|   successful response; every other status is treated as failure by mobile.
| - new money-moving clients should prefer `pin` or OTP (`sms`/`email`) and
|   should stop sending `verification_type = none`.
|
*/

Route::middleware('migration_flag:enable_verification')->group(function () {
    Route::post('verification-process/verify/otp', VerifyOtpController::class)
        ->middleware('throttle:maphapay-verification')
        ->name('maphapay.compat.verification.otp');

    Route::post('verification-process/verify/pin', VerifyPinController::class)
        ->middleware('throttle:maphapay-verification')
        ->name('maphapay.compat.verification.pin');
});

Route::middleware(['migration_flag:enable_send_money', 'kyc_approved', 'idempotency', 'throttle:maphapay-send-money'])
    ->post('send-money/store', SendMoneyStoreController::class)
    ->name('maphapay.compat.send-money.store');

Route::middleware(['migration_flag:enable_request_money', 'kyc_approved'])->group(function () {
    Route::post('request-money/store', RequestMoneyStoreController::class)
        ->middleware(['idempotency', 'throttle:maphapay-request-money'])
        ->name('maphapay.compat.request-money.store');

    Route::post('request-money/received-store/{moneyRequest}', RequestMoneyReceivedStoreController::class)
        ->middleware(['idempotency', 'throttle:maphapay-request-money'])
        ->name('maphapay.compat.request-money.received-store');

    Route::post('request-money/reject/{moneyRequest}', RequestMoneyRejectController::class)
        ->name('maphapay.compat.request-money.reject');

    Route::get('request-money/history', RequestMoneyHistoryController::class)
        ->name('maphapay.compat.request-money.history');

    Route::get('request-money/received-history', RequestMoneyReceivedHistoryController::class)
        ->name('maphapay.compat.request-money.received-history');
});

Route::middleware('migration_flag:enable_scheduled_send')->group(function () {
    Route::middleware(['idempotency'])
        ->post('scheduled-send/store', ScheduledSendStoreController::class)
        ->name('maphapay.compat.scheduled-send.store');

    Route::get('scheduled-send/index', ScheduledSendIndexController::class)
        ->name('maphapay.compat.scheduled-send.index');

    Route::post('scheduled-send/cancel/{scheduledSend}', ScheduledSendCancelController::class)
        ->name('maphapay.compat.scheduled-send.cancel');
});

Route::middleware(['migration_flag:enable_mtn_momo', 'kyc_approved'])->group(function () {
    Route::middleware(['idempotency', 'throttle:maphapay-mtn-initiation'])
        ->post('mtn/request-to-pay', RequestToPayController::class)
        ->name('maphapay.compat.mtn.request-to-pay');

    Route::middleware(['idempotency', 'throttle:maphapay-mtn-initiation'])
        ->post('mtn/disbursement', DisbursementController::class)
        ->name('maphapay.compat.mtn.disbursement');

    Route::get('mtn/transaction/{referenceId}/status', TransactionStatusController::class)
        ->name('maphapay.compat.mtn.transaction.status');

    Route::post('mtn/callback', CallbackController::class)
        ->withoutMiddleware([Authenticate::class, 'auth:sanctum', 'kyc_approved'])
        ->name('maphapay.compat.mtn.callback');
});

Route::middleware('migration_flag:enable_transaction_history')
    ->get('transactions', TransactionHistoryController::class)
    ->name('maphapay.compat.transactions.history');

Route::middleware('migration_flag:enable_dashboard')
    ->get('dashboard', DashboardController::class)
    ->name('maphapay.compat.dashboard');

Route::middleware('auth:sanctum')
    ->get('budget/categories', BudgetCategoriesController::class)
    ->name('maphapay.compat.budget.categories');

Route::middleware('auth:sanctum')
    ->get('social-money/threads', SocialThreadsController::class)
    ->name('maphapay.compat.social-money.threads');

Route::middleware('auth:sanctum')
    ->get('social-money/summary', SocialSummaryController::class)
    ->name('maphapay.compat.social-money.summary');

Route::middleware('auth:sanctum')
    ->controller(SocialChatCompatController::class)
    ->prefix('social-money')
    ->group(function (): void {
        Route::get('messages/{friendId}', 'messages')->name('maphapay.compat.social-money.messages');
        Route::post('send', 'send')->name('maphapay.compat.social-money.send');
        Route::post('send-bill-split', 'sendBillSplit')->name('maphapay.compat.social-money.send-bill-split');
        Route::post('mark-paid', 'markPaid')->name('maphapay.compat.social-money.mark-paid');
        Route::post('send-payment-message', 'sendPaymentMessage')->name('maphapay.compat.social-money.send-payment-message');
        Route::post('send-request-message', 'sendRequestMessage')->name('maphapay.compat.social-money.send-request-message');
        Route::post('amend-request-message', 'amendRequestMessage')->name('maphapay.compat.social-money.amend-request-message');
        Route::post('decline-request-message', 'declineRequestMessage')->name('maphapay.compat.social-money.decline-request-message');
        Route::post('cancel-request-message', 'cancelRequestMessage')->name('maphapay.compat.social-money.cancel-request-message');
    });

Route::middleware('auth:sanctum')
    ->get('social-money/friends', SocialFriendsController::class)
    ->name('maphapay.compat.social-money.friends');

Route::middleware('auth:sanctum')
    ->get('social-money/user-lookup/{query}', SocialUserLookupController::class)
    ->name('maphapay.compat.social-money.user-lookup');

Route::middleware('auth:sanctum')
    ->get('social-money/friendship-status/{userId}', SocialFriendshipStatusController::class)
    ->name('maphapay.compat.social-money.friendship-status');

Route::middleware('auth:sanctum')
    ->post('social-money/friend-requests', SocialFriendRequestStoreController::class)
    ->name('maphapay.compat.social-money.friend-requests.store');

Route::middleware('auth:sanctum')
    ->controller(SocialFriendRequestsController::class)
    ->prefix('social-money/friend-requests')
    ->group(function (): void {
        Route::get('incoming', 'incoming')->name('maphapay.compat.social-money.friend-requests.incoming');
        Route::get('outgoing', 'outgoing')->name('maphapay.compat.social-money.friend-requests.outgoing');
        Route::post('{id}/accept', 'accept')->name('maphapay.compat.social-money.friend-requests.accept');
        Route::post('{id}/reject', 'reject')->name('maphapay.compat.social-money.friend-requests.reject');
        Route::post('{id}/cancel', 'cancel')->name('maphapay.compat.social-money.friend-requests.cancel');
    });

Route::middleware('auth:sanctum')
    ->get('kyc-form', KycFormController::class)
    ->name('maphapay.compat.kyc-form');

Route::middleware('auth:sanctum')
    ->post('kyc-submit', KycSubmitController::class)
    ->name('maphapay.compat.kyc-submit');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('rewards', RewardsController::class)
        ->name('maphapay.compat.rewards.index');
    Route::get('rewards/points', RewardsPointsController::class)
        ->name('maphapay.compat.rewards.points');
});

Route::middleware('auth:sanctum')
    ->get('pockets', PocketsController::class)
    ->name('maphapay.compat.pockets');

Route::middleware('auth:sanctum')
    ->post('pockets/store', PocketsStoreController::class)
    ->name('maphapay.compat.pockets.store');

Route::middleware('auth:sanctum')
    ->post('pockets/update/{id}', PocketsUpdateController::class)
    ->name('maphapay.compat.pockets.update');

Route::middleware('auth:sanctum')
    ->post('pockets/add-funds/{id}', PocketsAddFundsController::class)
    ->name('maphapay.compat.pockets.add-funds');

Route::middleware('auth:sanctum')
    ->post('pockets/withdraw-funds/{id}', PocketsWithdrawFundsController::class)
    ->name('maphapay.compat.pockets.withdraw-funds');

Route::middleware('auth:sanctum')
    ->post('pockets/update-rules/{id}', PocketsUpdateRulesController::class)
    ->name('maphapay.compat.pockets.update-rules');

Route::middleware('auth:sanctum')
    ->get('wallet-linking', WalletLinkingController::class)
    ->name('maphapay.compat.wallet-linking');

Route::middleware('auth:sanctum')
    ->get('budget', BudgetController::class)
    ->name('maphapay.compat.budget');

Route::middleware('auth:sanctum')
    ->post('budget/update', BudgetUpdateController::class)
    ->name('maphapay.compat.budget.update');

Route::middleware('auth:sanctum')
    ->post('budget/categories', BudgetCategoriesStoreController::class)
    ->name('maphapay.compat.budget.categories.store');

Route::middleware('auth:sanctum')
    ->put('budget/categories/{id}', BudgetCategoriesUpdateController::class)
    ->name('maphapay.compat.budget.categories.update');

Route::middleware('auth:sanctum')
    ->delete('budget/categories/{id}', BudgetCategoriesDeleteController::class)
    ->name('maphapay.compat.budget.categories.delete');

Route::middleware('auth:sanctum')
    ->get('virtual-card/list', VirtualCardListController::class)
    ->name('maphapay.compat.virtual-card.list');

Route::middleware('auth:sanctum')
    ->get('virtual-card/view/{id}', VirtualCardViewController::class)
    ->name('maphapay.compat.virtual-card.view');

Route::middleware('auth:sanctum')
    ->post('virtual-card/ensure-default', VirtualCardEnsureDefaultController::class)
    ->name('maphapay.compat.virtual-card.ensure-default');

Route::middleware('auth:sanctum')
    ->post('virtual-card/store-additional', VirtualCardStoreAdditionalController::class)
    ->name('maphapay.compat.virtual-card.store-additional');

Route::middleware('auth:sanctum')
    ->post('virtual-card/add/fund/{id}', VirtualCardAddFundController::class)
    ->name('maphapay.compat.virtual-card.add-fund');

Route::middleware('auth:sanctum')
    ->post('virtual-card/cancel/{id}', VirtualCardCancelController::class)
    ->name('maphapay.compat.virtual-card.cancel');

Route::middleware('auth:sanctum')
    ->get('virtual-card/transaction', VirtualCardTransactionController::class)
    ->name('maphapay.compat.virtual-card.transaction');

// Catch-all: log any compat-prefix requests that don't match a defined route.
// This helps identify missing endpoints the mobile app is calling.
Route::any('{path}', function (string $path) {
    \Illuminate\Support\Facades\Log::warning('[compat:404] unmatched route', [
        'method' => request()->method(),
        'path'   => $path,
        'user'   => request()->user()?->id,
        'body'   => request()->except(['password', 'pin', 'otp']),
    ]);

    return response()->json(['message' => 'Not found.'], 404);
})->where('path', '.*')->name('maphapay.compat.fallback');
