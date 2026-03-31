<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Mtn;

use App\Domain\Asset\Models\Asset;
use App\Domain\MtnMomo\Services\MtnMomoClient;
use App\Domain\Shared\Money\MoneyConverter;
use App\Http\Controllers\Controller;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use App\Rules\MajorUnitAmountString;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * POST /api/mtn/request-to-pay — MaphaPay MTN MoMo collection (Phase 15).
 */
class RequestToPayController extends Controller
{
    public function __construct(
        private readonly MtnMomoClient $mtnMomoClient,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:191'],
            'amount'          => ['required', 'string', new MajorUnitAmountString()],
            'payer_msisdn'    => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
            'note'            => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency'        => ['sometimes', 'string', 'max:8'],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();

        $existing = MtnMomoTransaction::query()
            ->where('user_id', $authUser->id)
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing !== null) {
            return $this->successResponse($existing);
        }

        $currency = strtoupper((string) ($validated['currency'] ?? config('mtn_momo.currency', 'SZL')));
        $asset = Asset::query()->where('code', $currency)->first();

        if (! $asset) {
            return $this->errorResponse("Unknown asset/currency: {$currency}", 422);
        }

        try {
            $normalizedAmount = MoneyConverter::normalise($validated['amount'], $asset->precision);
        } catch (InvalidArgumentException) {
            return $this->errorResponse('Invalid amount.', 422);
        }

        $referenceId = (string) Str::uuid();
        $recordId = (string) Str::uuid();

        try {
            $txn = MtnMomoTransaction::query()->create([
                'id'               => $recordId,
                'user_id'          => $authUser->id,
                'idempotency_key'  => $validated['idempotency_key'],
                'type'             => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
                'amount'           => $normalizedAmount,
                'currency'         => $currency,
                'status'           => MtnMomoTransaction::STATUS_PENDING,
                'party_msisdn'     => (string) $validated['payer_msisdn'],
                'mtn_reference_id' => $referenceId,
                'note'             => $validated['note'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = MtnMomoTransaction::query()
                ->where('user_id', $authUser->id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($existing !== null) {
                return $this->successResponse($existing);
            }

            return $this->errorResponse('MTN request-to-pay could not be completed.', 503);
        }

        try {
            $this->mtnMomoClient->assertConfigured();
            $this->mtnMomoClient->requestToPay(
                $referenceId,
                $normalizedAmount,
                $currency,
                (string) $validated['payer_msisdn'],
                $validated['idempotency_key'],
                'MaphaPay collection',
                (string) ($validated['note'] ?? ''),
            );
        } catch (RuntimeException $e) {
            $txn->update(['status' => MtnMomoTransaction::STATUS_FAILED]);
            Log::error('MTN request-to-pay failed', [
                'mtn_reference_id' => $referenceId,
                'user_id'          => $authUser->id,
                'error'            => $e->getMessage(),
            ]);

            return $this->errorResponse('MTN request-to-pay could not be completed.', 503);
        } catch (Throwable $e) {
            $txn->update(['status' => MtnMomoTransaction::STATUS_FAILED]);
            Log::error('MTN request-to-pay unexpected error', [
                'mtn_reference_id' => $referenceId,
                'user_id'          => $authUser->id,
                'error'            => $e->getMessage(),
            ]);

            return $this->errorResponse('MTN request-to-pay could not be completed.', 503);
        }

        return $this->successResponse($txn->fresh() ?? $txn);
    }

    /**
     * @return array{status: string, remark: string, data: array{transaction: array<string, mixed>}}
     */
    private function successPayload(MtnMomoTransaction $txn): array
    {
        return [
            'status' => 'success',
            'remark' => 'mtn_request_to_pay',
            'data'   => [
                'transaction' => $this->transactionData($txn),
            ],
        ];
    }

    private function successResponse(MtnMomoTransaction $txn): JsonResponse
    {
        return response()->json($this->successPayload($txn));
    }

    /**
     * @return array{status: string, remark: string, message: array<int, string>}
     */
    private function errorPayload(string $message): array
    {
        return [
            'status'  => 'error',
            'remark'  => 'mtn_request_to_pay',
            'message' => [$message],
        ];
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json($this->errorPayload($message), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionData(MtnMomoTransaction $txn): array
    {
        return [
            'id'                           => $txn->id,
            'idempotency_key'              => $txn->idempotency_key,
            'type'                         => $txn->type,
            'amount'                       => $txn->amount,
            'currency'                     => $txn->currency,
            'status'                       => $txn->status,
            'party_msisdn'                 => $txn->party_msisdn,
            'mtn_reference_id'             => $txn->mtn_reference_id,
            'mtn_financial_transaction_id' => $txn->mtn_financial_transaction_id,
            'note'                         => $txn->note,
        ];
    }
}
