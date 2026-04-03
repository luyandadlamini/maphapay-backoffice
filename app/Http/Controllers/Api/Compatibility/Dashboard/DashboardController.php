<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Dashboard;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MaphaPay compatibility endpoint: dashboard snapshot for the authenticated user.
 *
 * Response envelope matches the legacy MaphaPay backend shape so the mobile
 * app's home screen and wallet screen can read it without changes:
 *
 *   {
 *     status: 'success',
 *     remark: 'dashboard',
 *     data: {
 *       user:    { id, email, mobile, balance, mobile_verified_at, kyc_status, has_completed_onboarding },
 *       balance: '1000.00',   // top-level duplicate for resilience
 *       offers:  []
 *     }
 *   }
 *
 * The user object mirrors the login response shape so the mobile app can use the
 * same status checks (has_completed_onboarding, kyc_status, mobile_verified_at)
 * without needing separate endpoints.
 *
 * Balance is cached per user for 30 seconds. User status fields are read fresh
 * each request as they change during onboarding/KYC flows.
 *
 * If the user has no account (not yet on-boarded) the balance returns as '0.00'.
 */
class DashboardController extends Controller
{
    private const CACHE_TTL_SECONDS = 30;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $balanceCacheKey = "maphapay.dashboard.balance.{$user->id}";

        $dashboardData = Cache::remember($balanceCacheKey, self::CACHE_TTL_SECONDS, function () use ($user): array {
            $szlAsset = Asset::find('SZL');
            $precision = $szlAsset !== null ? $szlAsset->precision : 2;
            $divisor = 10 ** $precision;
            $currencySymbol = config('banking.currency_symbol', 'E');

            $account = Account::where('user_uuid', $user->uuid)->first();
            $balanceMinor = $account !== null ? $account->getBalance('SZL') : 0;
            $balanceStr = number_format($balanceMinor / $divisor, $precision, '.', '');

            $accountUuids = $user->accounts()->pluck('uuid');
            $totalBalanceMinor = $accountUuids->isNotEmpty()
                ? AccountBalance::whereIn('account_uuid', $accountUuids)->where('asset_code', 'SZL')->sum('balance')
                : 0;
            $totalBalanceStr = number_format($totalBalanceMinor / $divisor, $precision, '.', '');

            return [
                'balance' => $balanceStr,
                'total_balance' => $totalBalanceStr,
                'currency_symbol' => $currencySymbol,
            ];
        });

        Log::info('[compat:dashboard] response', [
            'user_id' => $user->id,
            'mobile' => $user->mobile,
            'mobile_verified_at' => $user->mobile_verified_at?->toISOString(),
            'kyc_status' => $user->kyc_status,
            'has_completed_onboarding' => $user->has_completed_onboarding,
            'balance' => $dashboardData['balance'],
            'total_balance' => $dashboardData['total_balance'],
            'currency_symbol' => $dashboardData['currency_symbol'],
        ]);

        return response()->json([
            'status' => 'success',
            'remark' => 'dashboard',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'mobile' => $user->mobile,
                    'dial_code' => $user->dial_code,
                    'mobile_verified_at' => $user->mobile_verified_at?->toISOString(),
                    'kyc_status' => $user->kyc_status,
                    'has_completed_onboarding' => $user->has_completed_onboarding,
                    'balance' => $dashboardData['balance'],
                    'total_balance' => $dashboardData['total_balance'],
                    'currency_symbol' => $dashboardData['currency_symbol'],
                ],
                'balance' => $dashboardData['balance'],
                'total_balance' => $dashboardData['total_balance'],
                'currency_symbol' => $dashboardData['currency_symbol'],
                'offers' => [],
            ],
        ]);
    }
}
