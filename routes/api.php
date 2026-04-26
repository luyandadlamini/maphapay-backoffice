<?php

declare(strict_types=1);

// SPDX-License-Identifier: Apache-2.0
// Copyright (c) 2024-2026 FinAegis Contributors

use App\Http\Controllers\Api\Account\MinorCardController;
use App\Http\Controllers\Api\Auth\AccountDeletionController;
use App\Http\Controllers\Api\Auth\AuthorizationController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\MobileAuthController;
use App\Http\Controllers\Api\Auth\PasskeyController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\SocialAuthController;
use App\Http\Controllers\Api\Auth\TwoFactorAuthController;
use App\Http\Controllers\Api\Auth\UserOpSigningController;
use App\Http\Controllers\Api\Commerce\MinorMerchantBonusController;
use App\Http\Controllers\Api\CustodianWebhookController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\General\CountriesController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\OndatoWebhookController;
use App\Http\Controllers\Api\ProjectorHealthController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\LiveDashboardController;
use App\Http\Controllers\Api\V1\RampController;
use App\Http\Controllers\Api\V1\RampWebhookController;
use App\Http\Controllers\Api\V1\ReferralController;
use App\Http\Controllers\Api\V1\SponsorshipController;
use App\Http\Controllers\Api\Webhook\AlchemyWebhookController;
use App\Http\Controllers\Api\Webhook\HeliusWebhookController;
use App\Http\Controllers\Api\Webhook\HyperSwitchWebhookController;
use App\Http\Controllers\Api\Webhook\VisaCliWebhookController;
use App\Http\Controllers\Api\WebSocket\PaidChannelController;
use App\Http\Controllers\Api\WebSocketController;
use App\Http\Controllers\CoinbaseWebhookController;
use App\Infrastructure\Domain\ModuleRouteLoader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Orchestrator (v3.2.0)
|--------------------------------------------------------------------------
|
| Core routes (auth, monitoring, webhooks) are defined inline.
| Domain-specific routes are loaded from app/Domain/{Name}/Routes/api.php
| via the ModuleRouteLoader (modular architecture).
|
*/

// API root endpoint
Route::get('/', function () {
    return response()->json([
        'message'       => 'FinAegis Core Banking API',
        'version'       => 'v5',
        'documentation' => url('/api/documentation'),
        'status'        => route('status.api'),
        'endpoints'     => [
            'auth'         => url('/auth'),
            'accounts'     => url('/accounts'),
            'transactions' => url('/accounts/{uuid}/transactions'),
            'transfers'    => url('/transfers'),
            'exchange'     => url('/exchange'),
            'baskets'      => url('/baskets'),
            'stablecoins'  => url('/stablecoins'),
            'v2'           => url('/v2'),
        ],
    ]);
})->name('api.root');

// Monitoring endpoints (public - for Prometheus and Kubernetes)
Route::prefix('monitoring')->group(function () {
    Route::get('/metrics', [MonitoringController::class, 'prometheus'])->name('monitoring.metrics');
    Route::get('/prometheus', [MonitoringController::class, 'prometheus'])->name('monitoring.prometheus');
    Route::get('/health', [MonitoringController::class, 'health'])->name('monitoring.health');
    Route::get('/ready', [MonitoringController::class, 'ready'])->name('monitoring.ready');
    Route::get('/alive', [MonitoringController::class, 'alive'])->name('monitoring.alive');
});

// General endpoints (public)
Route::get('/countries', [CountriesController::class, 'index']);

// WebSocket configuration endpoints (public - for client initialization)
Route::prefix('websocket')->name('api.websocket.')->group(function () {
    Route::get('/config', [WebSocketController::class, 'config'])->name('config');
    Route::get('/status', [WebSocketController::class, 'status'])->name('status');
    Route::get('/channels/{type}', [WebSocketController::class, 'channelInfo'])->name('channel-info');
});

// WebSocket authenticated endpoints
Route::prefix('websocket')->name('api.websocket.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('/channels', [WebSocketController::class, 'channels'])->name('channels');
        Route::get('/subscriptions', [PaidChannelController::class, 'index'])->name('subscriptions.index');
        Route::delete('/subscriptions/{id}', [PaidChannelController::class, 'destroy'])->name('subscriptions.destroy');
    });

// Authentication endpoints (public)
Route::prefix('auth')->middleware('api.rate_limit:auth')->group(function () {
    Route::post('/register', [RegisterController::class, 'register']);
    Route::post('/login', [LoginController::class, 'login']);

    // Token refresh (public — accepts refresh token in body or Authorization header)
    Route::post('/refresh', [LoginController::class, 'refresh'])->middleware('throttle:20,1');

    // Password reset endpoints (public)
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // Mobile/PIN-based authentication (public)
    Route::prefix('mobile')->group(function () {
        Route::post('/login', [MobileAuthController::class, 'login']);
        Route::post('/verify-otp', [MobileAuthController::class, 'verifyOtp']);
        Route::post('/resend-otp', [MobileAuthController::class, 'resendOtp']);
        Route::post('/forgot-pin', [MobileAuthController::class, 'forgotPin']);
        Route::post('/verify-reset-code', [MobileAuthController::class, 'verifyResetCode']);
        Route::post('/reset-pin', [MobileAuthController::class, 'resetPin']);
    });

    // Email verification endpoints
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.verification.verify');

    // Social authentication endpoints
    Route::get('/social/{provider}', [SocialAuthController::class, 'redirect']);
    Route::post('/social/{provider}/callback', [SocialAuthController::class, 'callback']);

    // Protected auth endpoints
    Route::middleware(['auth:sanctum', 'account.context'])->group(function () {
        Route::post('/logout', [LoginController::class, 'logout']);
        Route::post('/logout-all', [LoginController::class, 'logoutAll']);
        Route::get('/user', [LoginController::class, 'user'])->withoutMiddleware('api.rate_limit:auth')->middleware('api.rate_limit:query');
        Route::get('/me', [LoginController::class, 'user'])->name('api.auth.me');
        Route::post('/delete-account', AccountDeletionController::class)->name('api.auth.delete-account');

        // Email verification resend
        Route::post('/resend-verification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1');

        // Two-factor authentication endpoints
        Route::prefix('2fa')->group(function () {
            Route::post('/enable', [TwoFactorAuthController::class, 'enable']);
            Route::post('/confirm', [TwoFactorAuthController::class, 'confirm']);
            Route::post('/disable', [TwoFactorAuthController::class, 'disable']);
            Route::post('/verify', [TwoFactorAuthController::class, 'verify']);
            Route::post('/recovery-codes', [TwoFactorAuthController::class, 'regenerateRecoveryCodes']);
        });

        // Mobile auth profile completion
        Route::post('/mobile/complete-profile', [MobileAuthController::class, 'completeProfile']);

        // Authorization status
        Route::get('/authorization', [AuthorizationController::class, 'index']);
        Route::post('/authorization/resend', [AuthorizationController::class, 'resend']);

        // UserOperation signing with auth shard (v2.6.0)
        Route::post('/sign-userop', [UserOpSigningController::class, 'sign'])
            ->middleware('throttle:10,1')
            ->name('api.auth.sign-userop');

        // Passkey registration (requires auth)
        Route::post('/passkey/register', [PasskeyController::class, 'register'])
            ->middleware('throttle:5,1')
            ->name('api.auth.passkey.register');
    });

    // Passkey aliases (public — authentication endpoints)
    Route::prefix('passkey')->middleware('throttle:5,1')->group(function () {
        Route::post('/challenge', [PasskeyController::class, 'challenge'])->name('api.auth.passkey.challenge');
        Route::get('/challenge', [PasskeyController::class, 'challenge'])->name('api.auth.passkey.challenge.get');
        Route::post('/verify', [PasskeyController::class, 'authenticate'])->name('api.auth.passkey.verify');
        Route::post('/authenticate', [PasskeyController::class, 'authenticate']);
    });
});

// User profile (avatar upload/delete)
Route::prefix('v1/users')->middleware(['auth:sanctum', 'account.context'])->group(function () {
    Route::post('/avatar', [UserProfileController::class, 'uploadAvatar'])->middleware('throttle:10,1')->name('api.users.avatar.upload');
    Route::delete('/avatar', [UserProfileController::class, 'deleteAvatar'])->middleware('api.rate_limit:query')->name('api.users.avatar.delete');
    Route::post('/transaction-pin/toggle', [UserProfileController::class, 'toggleTransactionPin'])->name('api.users.transaction-pin.toggle');
});

// Device token management (authenticated)
Route::middleware(['auth:sanctum', 'account.context'])->group(function () {
    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
});

// Legacy profile route for backward compatibility
Route::get('/profile', function (Request $request) {
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    return response()->json([
        'data' => [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'uuid'       => $user->uuid,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ],
    ]);
})->middleware(['auth:sanctum', 'deprecated:2026-09-01']);

// Legacy KYC documents endpoint for backward compatibility
Route::middleware(['auth:sanctum', 'deprecated:2026-09-01'])->post('/kyc/documents', [KycController::class, 'upload']);

// Custodian webhook endpoints (signature verification + webhook rate limiting)
Route::prefix('webhooks/custodian')->middleware(['api.rate_limit:webhook'])->group(function () {
    Route::post('/paysera', [CustodianWebhookController::class, 'paysera'])
        ->middleware('webhook.signature:paysera');
    Route::post('/santander', [CustodianWebhookController::class, 'santander'])
        ->middleware('webhook.signature:santander');
    Route::post('/mock', [CustodianWebhookController::class, 'mock']);
});

// Payment processor webhook endpoints
Route::prefix('webhooks')->middleware(['api.rate_limit:webhook'])->group(function () {
    Route::post('/coinbase-commerce', [CoinbaseWebhookController::class, 'handleWebhook'])
        ->middleware('webhook.signature:coinbase');
});

// Ondato KYC webhook endpoints
Route::prefix('webhooks/ondato')->middleware(['api.rate_limit:webhook'])->group(function () {
    Route::post('/identity-verification', [OndatoWebhookController::class, 'identityVerification']);
    Route::post('/identification', [OndatoWebhookController::class, 'identification']);
});

// Extended monitoring endpoints with authentication
Route::prefix('monitoring')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/metrics-json', [MonitoringController::class, 'metrics']);
    Route::get('/traces', [MonitoringController::class, 'traces']);
    Route::get('/trace/{traceId}', [MonitoringController::class, 'trace']);
    Route::get('/alerts', [MonitoringController::class, 'alerts']);
    Route::put('/alerts/{alertId}/acknowledge', [MonitoringController::class, 'acknowledgeAlert']);

    Route::get('/projector-health', [ProjectorHealthController::class, 'index']);
    Route::get('/projector-health/stale', [ProjectorHealthController::class, 'stale']);

    Route::middleware('is_admin')->group(function () {
        Route::post('/workflow/start', [MonitoringController::class, 'startWorkflow']);
        Route::post('/workflow/stop', [MonitoringController::class, 'stopWorkflow']);
    });
});

// v5.0.0 — Live Dashboard
Route::prefix('v1/monitoring/live-dashboard')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [LiveDashboardController::class, 'index']);
    Route::get('/domain-health', [LiveDashboardController::class, 'domainHealth']);
    Route::get('/event-throughput', [LiveDashboardController::class, 'eventThroughput']);
    Route::get('/stream-status', [LiveDashboardController::class, 'streamStatus']);
    Route::get('/projector-lag', [LiveDashboardController::class, 'projectorLag']);
});

// Admin dashboard endpoint (with 2FA requirement)
Route::prefix('admin')->middleware(['auth:sanctum', 'require.2fa.admin'])->group(function () {
    Route::get('/dashboard', function () {
        return response()->json([
            'message' => 'Admin dashboard',
            'user'    => auth()->user(),
        ]);
    });
});

// Passkey/WebAuthn Authentication (v2.7.0) - public assertion flow
Route::prefix('v1/auth/passkey')
    ->middleware('throttle:5,1')
    ->name('mobile.auth.passkey.')
    ->group(function () {
        Route::post('/challenge', [PasskeyController::class, 'challenge'])->name('challenge');
        Route::post('/authenticate', [PasskeyController::class, 'authenticate'])->name('authenticate');
    });

// Passkey registration (requires auth) - v1 path
Route::prefix('v1/auth/passkey')
    ->middleware(['auth:sanctum', 'throttle:5,1'])
    ->name('mobile.auth.passkey.authed.')
    ->group(function () {
        Route::post('/register-challenge', [PasskeyController::class, 'challenge'])->name('register-challenge');
        Route::post('/register', [PasskeyController::class, 'register'])->name('register');
    });

// v5.13.0 — Banners (promotional carousel)
Route::prefix('v1/banners')->name('api.v1.banners.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('/', [BannerController::class, 'index'])->name('index');
        Route::post('/{id}/dismiss', [BannerController::class, 'dismiss'])->name('dismiss');
    });

// v5.13.0 — On/Off Ramp
Route::prefix('v1/ramp')->name('api.v1.ramp.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('/supported', [RampController::class, 'supported'])->middleware('api.rate_limit:query')->name('supported');
        Route::get('/quotes', [RampController::class, 'quotes'])->middleware('api.rate_limit:query')->name('quotes');
        Route::post('/session', [RampController::class, 'createSession'])->name('session.create');
        Route::get('/session/{id}', [RampController::class, 'getSession'])->name('session.show');
        Route::get('/sessions', [RampController::class, 'listSessions'])->name('sessions');
    });

// v5.13.0 — Ramp Webhooks (no auth, HMAC verified)
Route::post('v1/ramp/webhook/{provider}', [RampWebhookController::class, 'handle'])
    ->middleware('api.rate_limit:webhook')
    ->name('api.v1.ramp.webhook');

// v5.14.0 — Alchemy Address Activity Webhook (no auth, HMAC verified)
Route::post('webhooks/alchemy/address-activity', [AlchemyWebhookController::class, 'handle'])
    ->middleware('api.rate_limit:webhook')
    ->name('api.webhooks.alchemy.address-activity');

// Helius Solana webhook (secret verified via Authorization header)
Route::post('webhooks/helius/solana', [HeliusWebhookController::class, 'handle'])
    ->middleware('api.rate_limit:webhook')
    ->name('api.webhooks.helius.solana');

// HyperSwitch payment lifecycle webhook (HMAC-SHA512 verified)
Route::post('webhooks/hyperswitch', [HyperSwitchWebhookController::class, 'handle'])
    ->middleware('api.rate_limit:webhook')
    ->name('api.webhooks.hyperswitch');

// Visa CLI payment status webhook (no auth, HMAC verified)
Route::post('webhooks/visa-cli/payment', [VisaCliWebhookController::class, 'handle'])
    ->middleware('api.rate_limit:webhook')
    ->name('api.webhooks.visacli.payment');

// v5.13.0 — Referral System
Route::prefix('v1/referrals')->name('api.v1.referrals.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('/my-code', [ReferralController::class, 'myCode'])->name('my-code');
        Route::post('/apply', [ReferralController::class, 'apply'])->name('apply');
        Route::get('/stats', [ReferralController::class, 'stats'])->name('stats');
        Route::get('/', [ReferralController::class, 'index'])->name('index');
    });

// v5.13.0 — Gas Sponsorship status
Route::prefix('v1/sponsorship')->name('api.v1.sponsorship.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('/status', [SponsorshipController::class, 'status'])->name('status');
    });

/*
|--------------------------------------------------------------------------
| External Route Includes
|--------------------------------------------------------------------------
*/

// Include BIAN-compliant routes
require __DIR__ . '/api-bian.php';

// Include V2 public API routes
Route::prefix('v2')->middleware('ensure.json')->group(function () {
    require __DIR__ . '/api-v2.php';
});

// Include fraud detection routes
require __DIR__ . '/api/fraud.php';

// Include enhanced regulatory routes
require __DIR__ . '/api/regulatory.php';

// Include module management API routes
require __DIR__ . '/api-modules.php';

/*
|--------------------------------------------------------------------------
| Domain Module Routes (v3.2.0)
|--------------------------------------------------------------------------
|
| All domain-specific routes are loaded from their respective
| app/Domain/{Name}/Routes/api.php files via ModuleRouteLoader.
| Disabled modules have their routes automatically skipped.
| See config/modules.php for module enable/disable configuration.
|
*/

app(ModuleRouteLoader::class)->loadRoutes();

// v5.14.0 — Internal API Routes (merchant QR payment hook)
Route::prefix('internal')->middleware('internal.api')->group(function () {
    Route::post('/minor-merchant-bonus/award', [MinorMerchantBonusController::class, 'award']);
});

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    Route::get('minor-cards/requests', [MinorCardController::class, 'listRequests']);
    Route::post('minor-cards/requests', [MinorCardController::class, 'createRequest']);
    Route::get('minor-cards/requests/{id}', [MinorCardController::class, 'showRequest']);
    Route::post('minor-cards/requests/{id}/approve', [MinorCardController::class, 'approveRequest']);
    Route::post('minor-cards/requests/{id}/deny', [MinorCardController::class, 'denyRequest']);
    Route::get('minor-cards', [MinorCardController::class, 'index']);
    Route::get('minor-cards/{cardId}', [MinorCardController::class, 'show']);
    Route::post('minor-cards/{cardId}/freeze', [MinorCardController::class, 'freeze']);
    Route::delete('minor-cards/{cardId}/freeze', [MinorCardController::class, 'unfreeze']);
    Route::post('minor-cards/{cardId}/provision', [MinorCardController::class, 'provision']);
});
