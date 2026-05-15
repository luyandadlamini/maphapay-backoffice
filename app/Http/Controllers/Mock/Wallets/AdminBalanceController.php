<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mock\Wallets;

use App\Domain\Wallet\Mock\MockWalletFundingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class AdminBalanceController extends Controller
{
    public function __invoke(string $provider, string $accountRef, MockWalletFundingService $funding): JsonResponse
    {
        if (! (bool) config('wallet_mocks.enabled')) {
            return response()->json(['message' => 'Wallet mocks are disabled.'], 403);
        }

        return response()->json($funding->getBalance($provider, $accountRef));
    }
}
