<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Compatibility\Budget\BudgetCategoriesController;
use App\Http\Controllers\Api\Compatibility\Budget\BudgetCategoriesDeleteController;
use App\Http\Controllers\Api\Compatibility\Budget\BudgetCategoriesStoreController;
use App\Http\Controllers\Api\Compatibility\Budget\BudgetCategoriesUpdateController;
use App\Http\Controllers\Api\Compatibility\Budget\BudgetController;
use App\Http\Controllers\Api\Compatibility\Budget\BudgetUpdateController;
use App\Http\Controllers\Api\Compatibility\Dashboard\DashboardController;
use App\Http\Controllers\Api\Compatibility\Kyc\KycFormController;
use App\Http\Controllers\Api\Compatibility\Kyc\KycSubmitController;
use App\Http\Controllers\Api\Compatibility\Mtn\CallbackController;
use App\Http\Controllers\Api\Compatibility\Mtn\DisbursementController;
use App\Http\Controllers\Api\Compatibility\Mtn\RequestToPayController;
use App\Http\Controllers\Api\Compatibility\Mtn\TransactionStatusController;
use App\Http\Controllers\Api\Compatibility\Notifications\NotificationSettingsController;
use App\Http\Controllers\Api\Compatibility\Notifications\PushNotificationsController;
use App\Http\Controllers\Api\Compatibility\Notifications\PushNotificationsReadController;
use App\Http\Controllers\Api\Compatibility\Notifications\PushNotificationsSyncController;
use App\Http\Controllers\Api\Compatibility\Pockets\PocketsAddFundsController;
use App\Http\Controllers\Api\Compatibility\Pockets\PocketsController;
use App\Http\Controllers\Api\Compatibility\Pockets\PocketsStoreController;
use App\Http\Controllers\Api\Compatibility\Pockets\PocketsSyncController;
use App\Http\Controllers\Api\Compatibility\Pockets\PocketsUpdateController;
use App\Http\Controllers\Api\Compatibility\Pockets\PocketsUpdateRulesController;
use App\Http\Controllers\Api\Compatibility\Pockets\PocketsWithdrawFundsController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyHistoryController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyReceivedHistoryController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyReceivedStoreController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyRejectController;
use App\Http\Controllers\Api\Compatibility\RequestMoney\RequestMoneyStoreController;
use App\Http\Controllers\Api\Compatibility\Rewards\RewardsController;
use App\Http\Controllers\Api\Compatibility\Rewards\RewardsPointsController;
use App\Http\Controllers\Api\Compatibility\Rewards\RewardsSyncController;
use App\Http\Controllers\Api\Compatibility\ScheduledSend\ScheduledSendCancelController;
use App\Http\Controllers\Api\Compatibility\ScheduledSend\ScheduledSendIndexController;
use App\Http\Controllers\Api\Compatibility\ScheduledSend\ScheduledSendStoreController;
use App\Http\Controllers\Api\Compatibility\SendMoney\SendMoneyStoreController;
use App\Http\Controllers\Api\Compatibility\SocialMoney\SocialFriendRequestsController;
use App\Http\Controllers\Api\Compatibility\SocialMoney\SocialFriendRequestStoreController;
use App\Http\Controllers\Api\Compatibility\SocialMoney\SocialFriendsController;
use App\Http\Controllers\Api\Compatibility\SocialMoney\SocialFriendshipStatusController;
use App\Http\Controllers\Api\Compatibility\SocialMoney\SocialSummaryController;
use App\Http\Controllers\Api\Compatibility\SocialMoney\SocialUserLookupController;
use App\Http\Controllers\Api\Compatibility\Transactions\TransactionCategoryUpdateController;
use App\Http\Controllers\Api\Compatibility\Transactions\TransactionHistoryController;
use App\Http\Controllers\Api\Compatibility\Transactions\TransactionSyncController;
use App\Http\Controllers\Api\Compatibility\VerificationProcess\ChallengeBiometricController;
use App\Http\Controllers\Api\Compatibility\VerificationProcess\VerifyBiometricController;
use App\Http\Controllers\Api\Compatibility\VerificationProcess\VerifyOtpController;
use App\Http\Controllers\Api\Compatibility\VerificationProcess\VerifyPinController;
use App\Http\Controllers\Api\Compatibility\VirtualCard\VirtualCardAddFundController;
use App\Http\Controllers\Api\Compatibility\VirtualCard\VirtualCardCancelController;
use App\Http\Controllers\Api\Compatibility\VirtualCard\VirtualCardEnsureDefaultController;
use App\Http\Controllers\Api\Compatibility\VirtualCard\VirtualCardListController;
use App\Http\Controllers\Api\Compatibility\VirtualCard\VirtualCardStoreAdditionalController;
use App\Http\Controllers\Api\Compatibility\VirtualCard\VirtualCardTransactionController;
use App\Http\Controllers\Api\Compatibility\VirtualCard\VirtualCardViewController;
use App\Http\Controllers\Api\Compatibility\WalletLinking\WalletLinkingController;
use App\Http\Controllers\Api\SocialMoney\GroupController;
use App\Http\Controllers\Api\SocialMoney\MessageController;
use App\Http\Controllers\Api\SocialMoney\ThreadBillSplitController;
use App\Http\Controllers\Api\SocialMoney\ThreadController;
use App\Http\Controllers\Api\SocialMoney\ThreadPaymentController;
use App\Http\Controllers\Api\SocialMoney\ThreadRequestController;
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
|   optional `asset_code`, and should send an Idempotency-Key header for replay-safe retries.
| - compat verification routes must fail closed: only `status = success` is a
|   successful response; every other status is treated as failure by mobile.
| - new money-moving clients should prefer `pin` or OTP (`sms`/`email`).
|   Request-money compat routes reject `verification_type = none`; send-money still
|   accepts it for backward compatibility.
|
*/

Route::middleware('migration_flag:enable_verification')->group(function () {
    Route::post('verification-process/challenge/biometric', ChallengeBiometricController::class)
        ->middleware(['auth:sanctum', 'throttle:maphapay-verification'])
        ->name('maphapay.compat.verification.biometric.challenge');

    Route::post('verification-process/verify/otp', VerifyOtpController::class)
        ->middleware('throttle:maphapay-verification')
        ->name('maphapay.compat.verification.otp');

    Route::post('verification-process/verify/pin', VerifyPinController::class)
        ->middleware('throttle:maphapay-verification')
        ->name('maphapay.compat.verification.pin');

    Route::post('verification-process/verify/biometric', VerifyBiometricController::class)
        ->middleware(['auth:sanctum', 'throttle:maphapay-verification'])
        ->name('maphapay.compat.verification.biometric.verify');
});

Route::middleware(['migration_flag:enable_send_money', 'kyc_approved', 'idempotency', 'throttle:maphapay-send-money'])
    ->post('send-money/store', SendMoneyStoreController::class)
    ->name('maphapay.compat.send-money.store');

Route::middleware(['migration_flag:enable_request_money', 'kyc_approved'])->group(function () {
    Route::post('request-money/store', RequestMoneyStoreController::class)
        ->middleware(['migration_flag:enable_request_money_create', 'idempotency', 'throttle:maphapay-request-money'])
        ->name('maphapay.compat.request-money.store');

    Route::post('request-money/received-store/{moneyRequest}', RequestMoneyReceivedStoreController::class)
        ->middleware(['migration_flag:enable_request_money_accept', 'idempotency', 'throttle:maphapay-request-money'])
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

Route::middleware('migration_flag:enable_transaction_history')
    ->get('transactions/sync', TransactionSyncController::class)
    ->name('maphapay.compat.transactions.sync');

Route::middleware('migration_flag:enable_transaction_history')
    ->patch('transactions/{transactionUuid}/category', TransactionCategoryUpdateController::class)
    ->name('maphapay.compat.transactions.category.update');

Route::middleware('migration_flag:enable_dashboard')
    ->get('dashboard', DashboardController::class)
    ->name('maphapay.compat.dashboard');

Route::middleware('auth:sanctum')
    ->get('budget/categories', BudgetCategoriesController::class)
    ->name('maphapay.compat.budget.categories');

Route::prefix('social-money')->middleware('auth:sanctum')->group(function (): void {
    Route::get('threads', [ThreadController::class, 'index']);
    Route::post('threads/direct', [ThreadController::class, 'createDirect']);
    Route::get('threads/{threadId}/messages', [MessageController::class, 'index']);
    Route::post('threads/{threadId}/send', [MessageController::class, 'send']);
    Route::post('threads/{threadId}/typing', [MessageController::class, 'typing']);
    Route::post('threads/{threadId}/read', [MessageController::class, 'markRead']);
    Route::post('threads/{threadId}/bill-split', [ThreadBillSplitController::class, 'store']);
    Route::post('bill-splits/{billSplitId}/mark-paid', [ThreadBillSplitController::class, 'markPaid']);
    Route::post('threads/{threadId}/request', [ThreadRequestController::class, 'store']);
    Route::post('requests/{requestId}/decline', [ThreadRequestController::class, 'decline']);
    Route::post('requests/{requestId}/cancel', [ThreadRequestController::class, 'cancel']);
    Route::post('requests/{requestId}/amend', [ThreadRequestController::class, 'amend']);
    Route::post('threads/{threadId}/payment', [ThreadPaymentController::class, 'store']);

    Route::post('groups', [GroupController::class, 'store']);
    Route::patch('groups/{threadId}', [GroupController::class, 'update']);
    Route::delete('groups/{threadId}', [GroupController::class, 'destroy']);
    Route::post('groups/{threadId}/members', [GroupController::class, 'addMembers']);
    Route::delete('groups/{threadId}/members/{userId}', [GroupController::class, 'removeMember']);
    Route::post('groups/{threadId}/leave', [GroupController::class, 'leave']);
    Route::post('groups/{threadId}/members/{userId}/role', [GroupController::class, 'changeRole']);
});

Route::middleware('auth:sanctum')
    ->get('social-money/summary', SocialSummaryController::class)
    ->name('maphapay.compat.social-money.summary');

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
    Route::get('rewards/sync', RewardsSyncController::class)
        ->name('maphapay.compat.rewards.sync');
});

Route::middleware('auth:sanctum')
    ->get('pockets', PocketsController::class)
    ->name('maphapay.compat.pockets');

Route::middleware('auth:sanctum')
    ->get('pockets/sync', PocketsSyncController::class)
    ->name('maphapay.compat.pockets.sync');

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
    ->get('push-notifications', PushNotificationsController::class)
    ->name('maphapay.compat.push-notifications.index');

Route::middleware('auth:sanctum')
    ->post('push-notifications/read/{id}', PushNotificationsReadController::class)
    ->name('maphapay.compat.push-notifications.read');

Route::middleware('auth:sanctum')
    ->get('push-notifications/sync', PushNotificationsSyncController::class)
    ->name('maphapay.compat.push-notifications.sync');

Route::middleware('auth:sanctum')
    ->controller(NotificationSettingsController::class)
    ->prefix('notification/settings')
    ->group(function (): void {
        Route::get('', 'show')->name('maphapay.compat.notification-settings.show');
        Route::post('', 'update')->name('maphapay.compat.notification-settings.update');
    });

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

Route::prefix('savings/group-pockets')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [\App\Http\Controllers\Api\GroupSavings\GroupPocketController::class, 'index']);
    Route::get('thread/{threadId}', [\App\Http\Controllers\Api\GroupSavings\GroupPocketController::class, 'byThread']);
    Route::post('/', [\App\Http\Controllers\Api\GroupSavings\GroupPocketController::class, 'store']);
    Route::patch('{id}', [\App\Http\Controllers\Api\GroupSavings\GroupPocketController::class, 'update']);
    Route::delete('{id}', [\App\Http\Controllers\Api\GroupSavings\GroupPocketController::class, 'destroy']);
});

// Catch-all: log any compat-prefix requests that don't match a defined route.
// This helps identify missing endpoints the mobile app is calling.
Route::any('{path}', function (string $path) {
    Illuminate\Support\Facades\Log::warning('[compat:404] unmatched route', [
        'method' => request()->method(),
        'path'   => $path,
        'user'   => request()->user()?->id,
        'body'   => request()->except(['password', 'pin', 'otp']),
    ]);

    return response()->json(['message' => 'Not found.'], 404);
})->where('path', '.*')->name('maphapay.compat.fallback');
