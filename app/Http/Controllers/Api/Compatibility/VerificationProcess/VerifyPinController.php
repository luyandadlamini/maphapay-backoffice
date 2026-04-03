<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\InvalidTransactionPinException;
use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Exceptions\TransactionPinNotSetException;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * POST /api/verification-process/verify/pin.
 *
 * Mobile sends: { trx: string, pin: string, remark: string }
 * Phase 0 contract freeze: only `status: success` is a successful response.
 * Every other status must be treated as verification failure by the client.
 */
class VerifyPinController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionManager $manager,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trx' => ['required', 'string'],
            'pin' => ['required', 'string', 'digits:4'],
            'remark' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            $remark = (string) $request->input('remark', 'pin_verified');
            $message = (string) ($validator->errors()->first() ?: 'Invalid verification request.');

            $this->telemetry->logVerificationFailure(
                $request,
                'pin',
                $remark,
                is_string($request->input('trx')) ? $request->input('trx') : null,
                $message,
                422,
                $this->findTransaction($request, is_string($request->input('trx')) ? $request->input('trx') : null),
            );

            return response()->json([
                'status' => 'error',
                'remark' => $remark,
                'message' => [$message],
                'data' => null,
            ], 422);
        }

        /** @var array{trx: string, pin: string, remark?: string} $validated */
        $validated = $validator->validated();

        try {
            $result = $this->manager->verifyPin(
                trx: $validated['trx'],
                userId: (int) $request->user()?->getAuthIdentifier(),
                pin: $validated['pin'],
            );
            $transaction = $this->findTransaction($request, $validated['trx']);

            $this->telemetry->logEvent('verification_succeeded', $this->telemetry->requestContext($request, [
                'verification_method' => 'pin',
                'remark' => $validated['remark'] ?? 'pin_verified',
                'trx' => $validated['trx'],
            ] + $this->telemetry->transactionContext($transaction)));

            return response()->json([
                'status' => 'success',
                'remark' => $validated['remark'] ?? 'pin_verified',
                'data' => $result,
            ]);
        } catch (TransactionNotFoundException $e) {
            $this->telemetry->logVerificationFailure(
                $request,
                'pin',
                $validated['remark'] ?? 'pin_verified',
                $validated['trx'],
                $e->getMessage(),
                404,
                $this->findTransaction($request, $validated['trx']),
            );

            return $this->errorResponse(
                $validated['remark'] ?? 'pin_verified',
                $e->getMessage(),
                404,
            );
        } catch (TransactionPinNotSetException|InvalidTransactionPinException $e) {
            $this->telemetry->logVerificationFailure(
                $request,
                'pin',
                $validated['remark'] ?? 'pin_verified',
                $validated['trx'],
                $e->getMessage(),
                422,
                $this->findTransaction($request, $validated['trx']),
            );

            return $this->errorResponse(
                $validated['remark'] ?? 'pin_verified',
                $e->getMessage(),
                422,
            );
        } catch (RuntimeException $e) {
            $this->telemetry->logVerificationFailure(
                $request,
                'pin',
                $validated['remark'] ?? 'pin_verified',
                $validated['trx'],
                $e->getMessage(),
                422,
                $this->findTransaction($request, $validated['trx']),
            );

            return $this->errorResponse(
                $validated['remark'] ?? 'pin_verified',
                $e->getMessage(),
                422,
            );
        }
    }

    private function errorResponse(string $remark, string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'remark' => $remark,
            'message' => [$message],
            'data' => null,
        ], $status);
    }

    private function findTransaction(Request $request, ?string $trx): ?AuthorizedTransaction
    {
        if ($trx === null || $trx === '') {
            return null;
        }

        $userId = (int) $request->user()?->getAuthIdentifier();
        if ($userId <= 0) {
            return null;
        }

        return AuthorizedTransaction::query()
            ->where('trx', $trx)
            ->where('user_id', $userId)
            ->first();
    }
}
