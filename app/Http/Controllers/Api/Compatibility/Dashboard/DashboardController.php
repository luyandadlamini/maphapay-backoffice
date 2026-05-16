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
 * Balance is always SZL (the canonical user-facing currency in Eswatini).
 * Accounts funded only in other assets (e.g. USD from Stripe card provisioning)
 * report 0.00 here — by design, so the displayed balance never disagrees with
 * what send-money will accept. Crediting non-SZL inflows back to SZL is the
 * responsibility of the funding pipeline, not this read endpoint.
 *
 * Balance is cached per user for 30 seconds. User status fields are read fresh
 * each request as they change during onboarding/KYC flows.
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

            // @phpstan-ignore argument.type
            $accountUuids = $user->accounts()->pluck('uuid');
            $totalBalanceMinor = $accountUuids->isNotEmpty()
                ? AccountBalance::whereIn('account_uuid', $accountUuids)->where('asset_code', 'SZL')->sum('balance')
                : 0;
            $totalBalanceStr = number_format($totalBalanceMinor / $divisor, $precision, '.', '');

            return [
                'balance'         => $balanceStr,
                'total_balance'   => $totalBalanceStr,
                'currency_symbol' => $currencySymbol,
            ];
        });

        Log::info('[compat:dashboard] response', [
            'user_id'                  => $user->id,
            'mobile'                   => $user->mobile,
            'mobile_verified_at'       => $user->mobile_verified_at?->toISOString(),
            'kyc_status'               => $user->kyc_status,
            'has_completed_onboarding' => $user->has_completed_onboarding,
            'balance'                  => $dashboardData['balance'],
            'total_balance'            => $dashboardData['total_balance'],
            'currency_symbol'          => $dashboardData['currency_symbol'],
        ]);

        return response()->json([
            'status' => 'success',
            'remark' => 'dashboard',
            'data'   => [
                'user' => [
                    'id'                       => $user->id,
                    'email'                    => $user->email,
                    'mobile'                   => $user->mobile,
                    'dial_code'                => $user->dial_code,
                    'mobile_verified_at'       => $user->mobile_verified_at?->toISOString(),
                    'kyc_status'               => $user->kyc_status,
                    'has_completed_onboarding' => $user->has_completed_onboarding,
                    'balance'                  => $dashboardData['balance'],
                    'total_balance'            => $dashboardData['total_balance'],
                    'currency_symbol'          => $dashboardData['currency_symbol'],
                ],
                'balance'         => $dashboardData['balance'],
                'total_balance'   => $dashboardData['total_balance'],
                'currency_symbol' => $dashboardData['currency_symbol'],
                'offers'          => [],
            ],
        ]);
    }
}
