<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Mtn;

use App\Domain\MtnMomo\Services\MtnMomoCollectionSettler;
use App\Http\Controllers\Controller;
use App\Models\MtnMomoTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/mtn/callback — MTN MoMo IPN (no Sanctum; verify X-Callback-Token).
 */
class CallbackController extends Controller
{
    public function __construct(
        private readonly MtnMomoCollectionSettler $collectionSettler,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (config('mtn_momo.verify_callback_token', true)) {
            $expected = (string) config('mtn_momo.callback_token', '');

            if ($expected === '') {
                Log::warning('MTN callback token verification is enabled but MTNMOMO_CALLBACK_TOKEN is not set.');

                return response('', 401);
            }

            $incoming = (string) $request->header('X-Callback-Token', '');

            if ($incoming === '' || ! hash_equals($expected, $incoming)) {
                return response('', 401);
            }
        }

        $referenceId = (string) $request->header('X-Reference-Id', '');
        if ($referenceId === '') {
            return response('', 400);
        }

        /** @var array<string, mixed> $body */
        $body = $request->all();

        $txn = MtnMomoTransaction::query()
            ->where('mtn_reference_id', $referenceId)
            ->first();

        if ($txn === null) {
            Log::warning('MTN callback received for unknown reference ID', [
                'mtn_reference_id' => $referenceId,
            ]);

            // Return 200 to prevent MTN from retrying; do not leak whether the ID exists.
            return response('', 200);
        }

        $remoteStatus = $this->mtnStatusFrom($body);
        $normalized = MtnMomoTransaction::normaliseRemoteStatus($remoteStatus);
        $financialId = $this->mtnFinancialIdFrom($body);

        $txn->update([
            'last_mtn_status'              => $remoteStatus,
            'status'                       => $normalized,
            'mtn_financial_transaction_id' => $financialId ?? $txn->mtn_financial_transaction_id,
        ]);

        $fresh = $txn->fresh();
        if (
            $fresh !== null
            && $fresh->type === MtnMomoTransaction::TYPE_REQUEST_TO_PAY
            && $normalized === MtnMomoTransaction::STATUS_SUCCESSFUL
        ) {
            $user = $fresh->user;
            if ($user !== null) {
                $this->collectionSettler->creditIfNeeded($fresh, $user);
            }
        }

        return response('', 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mtnStatusFrom(array $payload): ?string
    {
        $s = $payload['status'] ?? null;

        return is_string($s) ? $s : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mtnFinancialIdFrom(array $payload): ?string
    {
        foreach (['financialTransactionId', 'financial_transaction_id'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
