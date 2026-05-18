<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\WalletLinking;

use App\Domain\Wallet\Models\WalletLinking;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/wallet-linking.
 *
 * Upserts a wallet link for the authenticated user. Re-linking the same
 * (provider, account_ref) updates link_status / metadata in place.
 */
final class WalletLinkingStoreController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider'    => ['required', 'string', Rule::in(WalletLinking::PROVIDERS)],
            'account_ref' => ['required', 'string', 'max:64'],
            'currency'    => ['required', 'string', 'size:3'],
            'link_status' => ['required', 'string', Rule::in([
                WalletLinking::STATUS_ACTIVE,
                WalletLinking::STATUS_PENDING,
                WalletLinking::STATUS_FAILED,
            ])],
            'metadata' => ['sometimes', 'array'],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();

        $linking = WalletLinking::query()->updateOrCreate(
            [
                'user_id'     => $authUser->getAuthIdentifier(),
                'provider'    => $validated['provider'],
                'account_ref' => $validated['account_ref'],
            ],
            [
                'currency'    => $validated['currency'],
                'link_status' => $validated['link_status'],
                'metadata'    => $validated['metadata'] ?? null,
                'linked_at'   => now(),
            ],
        );

        return response()->json([
            'status' => 'success',
            'remark' => 'wallet_linking_store',
            'data'   => [
                'linking' => [
                    'id'          => $linking->id,
                    'provider'    => $linking->provider,
                    'account_ref' => $linking->account_ref,
                    'currency'    => $linking->currency,
                    'link_status' => $linking->link_status,
                    'linked_at'   => $linking->linked_at->toIso8601String(),
                ],
            ],
        ]);
    }
}
