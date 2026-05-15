<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mock\Wallets\FnbEwallet;

use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Mock\Jobs\DispatchMockWalletCallbackJob;
use App\Domain\Wallet\Mock\MockFailureRules;
use App\Domain\Wallet\Mock\MockWalletStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransferController extends Controller
{
    private const PROVIDER_ID = 'fnb_ewallet';

    public function create(Request $request, MockWalletStore $store): JsonResponse
    {
        $referenceId = (string) $request->input('reference_id', '');
        if ($referenceId === '') {
            return response()->json(['message' => 'Missing reference_id.'], 400);
        }

        $amount = (string) $request->input('amount', '0');
        $payee = (string) $request->input('payee.mobile', '');
        $amountMinor = MoneyConverter::toSmallestUnit($amount, 2);

        $sync = MockFailureRules::syncOutcome($payee, $amountMinor);
        if ($sync === MockFailureRules::SYNC_DUPLICATE_409) {
            return response()->json(['message' => 'Duplicate reference_id.'], 409);
        }
        if ($sync === MockFailureRules::SYNC_TRANSIENT_500) {
            return response()->json(['message' => 'Transient provider failure.'], 500);
        }

        $store->putRequest(self::PROVIDER_ID, 'disburse', $referenceId, [
            'account_ref'   => $payee,
            'amount'        => $amount,
            'amount_minor'  => $amountMinor,
            'currency'      => (string) $request->input('currency', 'SZL'),
            'external_id'   => (string) $request->input('external_id', ''),
            'narration'     => (string) $request->input('narration', ''),
            'status'        => 'PENDING',
            'provider_kind' => 'transfer',
            'provider_id'   => self::PROVIDER_ID,
        ]);

        DispatchMockWalletCallbackJob::dispatch(self::PROVIDER_ID, 'disburse', $referenceId)
            ->delay(now()->addSeconds((int) config('wallet_mocks.providers.' . self::PROVIDER_ID . '.callback_delay_seconds', 2)));

        return response()->json(['reference_id' => $referenceId, 'status' => 'PENDING'], 202);
    }

    public function show(string $ref, MockWalletStore $store): JsonResponse
    {
        $req = $store->getRequest(self::PROVIDER_ID, 'disburse', $ref);
        if ($req === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $body = [
            'reference_id' => $ref,
            'external_id'  => (string) ($req['external_id'] ?? ''),
            'amount'       => (string) ($req['amount'] ?? ''),
            'currency'     => (string) ($req['currency'] ?? 'SZL'),
            'payee'        => ['mobile' => (string) ($req['account_ref'] ?? '')],
            'status'       => (string) ($req['status'] ?? 'PENDING'),
        ];

        if (isset($req['financial_transaction_id'])) {
            $body['financial_transaction_id'] = (string) $req['financial_transaction_id'];
        }
        if (isset($req['reason'])) {
            $body['reason'] = (string) $req['reason'];
        }

        return response()->json($body);
    }
}
