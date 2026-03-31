<?php

declare(strict_types=1);

use App\Http\Controllers\API\Compatibility\Budget\BudgetCategoriesController;
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
*/

Route::middleware('migration_flag:enable_verification')->group(function () {
    Route::post('verification-process/verify/otp', VerifyOtpController::class)
        ->name('maphapay.compat.verification.otp');

    Route::post('verification-process/verify/pin', VerifyPinController::class)
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
