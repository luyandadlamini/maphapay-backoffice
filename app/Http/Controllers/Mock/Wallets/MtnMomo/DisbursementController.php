<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mock\Wallets\MtnMomo;

use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Mock\Jobs\DispatchMockWalletCallbackJob;
use App\Domain\Wallet\Mock\MockFailureRules;
use App\Domain\Wallet\Mock\MockWalletStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DisbursementController extends Controller
{
    public function create(Request $request, MockWalletStore $store): Response|JsonResponse
    {
        $referenceId = (string) $request->header('X-Reference-Id', '');
        if ($referenceId === '') {
            return response()->json(['message' => 'Missing X-Reference-Id.'], 400);
        }

        $amount = (string) $request->input('amount', '0');
        $payeeMsisdn = (string) $request->input('payee.partyId', '');
        $amountMinor = MoneyConverter::toSmallestUnit($amount, 2);

        $syncOutcome = MockFailureRules::syncOutcome($payeeMsisdn, $amountMinor);
        if ($syncOutcome === MockFailureRules::SYNC_DUPLICATE_409) {
            return response()->json(['message' => 'Duplicate provider request.'], 409);
        }

        if ($syncOutcome === MockFailureRules::SYNC_TRANSIENT_500) {
            return response()->json(['message' => 'Transient provider failure.'], 500);
        }

        $store->putRequest('mtn_momo', 'disburse', $referenceId, [
            'account_ref'   => $payeeMsisdn,
            'amount'        => $amount,
            'amount_minor'  => $amountMinor,
            'currency'      => (string) $request->input('currency', 'SZL'),
            'external_id'   => (string) $request->input('externalId', ''),
            'payer_message' => (string) $request->input('payerMessage', ''),
            'payee_note'    => (string) $request->input('payeeNote', ''),
            'status'        => 'PENDING',
            'provider_kind' => 'disbursement',
            'provider_id'   => 'mtn_momo',
        ]);

        DispatchMockWalletCallbackJob::dispatch('mtn_momo', 'disburse', $referenceId)
            ->delay(now()->addSeconds((int) config('wallet_mocks.providers.mtn_momo.callback_delay_seconds', 2)));

        return response('', 202);
    }

    public function show(string $ref, MockWalletStore $store): JsonResponse
    {
        $request = $store->getRequest('mtn_momo', 'disburse', $ref);
        if ($request === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $body = [
            'externalId' => (string) ($request['external_id'] ?? ''),
            'amount'     => (string) ($request['amount'] ?? ''),
            'currency'   => (string) ($request['currency'] ?? 'SZL'),
            'payee'      => [
                'partyIdType' => 'MSISDN',
                'partyId'     => (string) ($request['account_ref'] ?? ''),
            ],
            'status' => (string) ($request['status'] ?? 'PENDING'),
        ];

        if (isset($request['financial_transaction_id'])) {
            $body['financialTransactionId'] = (string) $request['financial_transaction_id'];
        }

        if (isset($request['reason'])) {
            $body['reason'] = (string) $request['reason'];
        }

        return response()->json($body);
    }
}
