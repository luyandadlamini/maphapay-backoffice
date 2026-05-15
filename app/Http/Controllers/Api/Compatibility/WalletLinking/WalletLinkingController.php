<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\WalletLinking;

use App\Domain\Wallet\Models\WalletLinking;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/wallet-linking.
 *
 * Returns linked external wallets for the authenticated user.
 */
final class WalletLinkingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $query = WalletLinking::query()
            ->where('user_id', $authUser->getAuthIdentifier())
            ->orderByDesc('linked_at');

        if ($provider = $request->string('provider')->toString()) {
            $query->where('provider', $provider);
        }

        $wallets = $query->get()->map(static fn (WalletLinking $row): array => [
            'id'           => $row->id,
            'provider'     => $row->provider,
            'account_ref'  => $row->account_ref,
            'currency'     => $row->currency,
            'link_status'  => $row->link_status,
            'linked_at'    => $row->linked_at->toIso8601String(),
            'last_used_at' => $row->last_used_at?->toIso8601String(),
        ])->all();

        return response()->json([
            'status' => 'success',
            'remark' => 'wallet_linking_index',
            'data'   => ['wallets' => $wallets],
        ]);
    }
}
