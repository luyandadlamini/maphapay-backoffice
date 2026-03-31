<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Dashboard;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
 *       user:    { id, email, mobile, balance },
 *       balance: '1000.00',   // top-level duplicate for resilience
 *       offers:  []
 *     }
 *   }
 *
 * Balance is the SZL account balance converted to major-unit string.
 * The response is cached per user for 30 seconds to avoid N+1 queries on every app open.
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

        $cacheKey = "maphapay.dashboard.{$user->id}";

        $data = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user): array {
            $account = Account::where('user_uuid', $user->uuid)->first();

            $balanceMinor = 0;
            if ($account !== null) {
                $balanceMinor = $account->getBalance('SZL');
            }

            $szlAsset = Asset::find('SZL');
            $precision = $szlAsset !== null ? $szlAsset->precision : 2;
            $divisor = 10 ** $precision;
            $balanceStr = number_format($balanceMinor / $divisor, $precision, '.', '');

            return [
                'user' => [
                    'id'      => $user->id,
                    'email'   => $user->email,
                    'mobile'  => $user->mobile,
                    'balance' => $balanceStr,
                ],
                'balance' => $balanceStr,
                'offers'  => [],
            ];
        });

        return response()->json([
            'status' => 'success',
            'remark' => 'dashboard',
            'data'   => $data,
        ]);
    }
}
