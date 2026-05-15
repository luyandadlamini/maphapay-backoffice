<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\WalletLinking;

use App\Domain\Wallet\Models\WalletLinking;
use App\Http\Controllers\Controller;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DELETE /api/wallet-linking/{linking}.
 *
 * Soft-deletes the linking, marks it as disabled, and writes an audit row.
 */
final class WalletLinkingDestroyController extends Controller
{
    public function __invoke(Request $request, int $linking): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $row = WalletLinking::query()
            ->where('id', $linking)
            ->where('user_id', $authUser->getAuthIdentifier())
            ->firstOrFail();

        $row->fill([
            'link_status'         => WalletLinking::STATUS_DISABLED,
            'disabled_at'         => now(),
            'disabled_by_user_id' => $authUser->getAuthIdentifier(),
        ])->save();

        $row->delete();

        SecurityAuditLog::query()->create([
            'event_type'  => 'wallet.linking_disabled',
            'severity'    => 'high',
            'user_id'     => $authUser->getAuthIdentifier(),
            'reason'      => "Wallet linking disabled for user {$row->user_id} ({$row->provider})",
            'occurred_at' => now(),
            'context'     => [
                'linking_id' => $row->id,
                'provider'   => $row->provider,
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'remark' => 'wallet_linking_destroy',
            'data'   => [],
        ]);
    }
}
