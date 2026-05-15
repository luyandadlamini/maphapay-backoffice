<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mock\Wallets;

use App\Domain\Wallet\Mock\MockWalletFundingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminFundController extends Controller
{
    public function __invoke(Request $request, string $provider, MockWalletFundingService $funding): JsonResponse
    {
        if (! (bool) config('wallet_mocks.enabled')) {
            return response()->json(['message' => 'Wallet mocks are disabled.'], 403);
        }

        $validated = $request->validate([
            'account_ref'  => ['required', 'string', 'max:191'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'currency'     => ['required', 'string', 'size:3'],
            'reset'        => ['sometimes', 'boolean'],
        ]);

        $result = (bool) ($validated['reset'] ?? false)
            ? $funding->setBalance($provider, $validated['account_ref'], (int) $validated['amount_minor'], strtoupper($validated['currency']))
            : $funding->fund($provider, $validated['account_ref'], (int) $validated['amount_minor'], strtoupper($validated['currency']));

        return response()->json($result);
    }
}
