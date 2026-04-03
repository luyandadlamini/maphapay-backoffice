<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /api/verification-process/verify/otp.
 *
 * Mobile sends: { trx: string, otp: string, remark: string }
 * Phase 0 contract freeze: only `status: success` is a successful response.
 * Every other status must be treated as verification failure by the client.
 */
class VerifyOtpController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionManager $manager,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trx' => ['required', 'string'],
            'otp' => ['required', 'string', 'digits:6'],
            'remark' => ['sometimes', 'string'],
        ]);

        try {
            $result = $this->manager->verifyOtp(
                trx: $validated['trx'],
                userId: (int) $request->user()?->getAuthIdentifier(),
                otp: $validated['otp'],
            );
            $transaction = $this->findTransaction($request, $validated['trx']);

            $this->telemetry->logEvent('verification_succeeded', $this->telemetry->requestContext($request, [
                'verification_method' => 'otp',
                'remark' => $validated['remark'] ?? 'otp_verified',
                'trx' => $validated['trx'],
            ] + $this->telemetry->transactionContext($transaction)));

            return response()->json([
                'status' => 'success',
                'remark' => $validated['remark'] ?? 'otp_verified',
                'data' => $result,
            ]);
        } catch (TransactionNotFoundException $e) {
            $this->telemetry->logVerificationFailure(
                $request,
                'otp',
                $validated['remark'] ?? 'otp_verified',
                $validated['trx'],
                $e->getMessage(),
                404,
                $this->findTransaction($request, $validated['trx']),
            );

            return $this->errorResponse(
                $validated['remark'] ?? 'otp_verified',
                $e->getMessage(),
                404,
            );
        } catch (RuntimeException $e) {
            $this->telemetry->logVerificationFailure(
                $request,
                'otp',
                $validated['remark'] ?? 'otp_verified',
                $validated['trx'],
                $e->getMessage(),
                422,
                $this->findTransaction($request, $validated['trx']),
            );

            return $this->errorResponse(
                $validated['remark'] ?? 'otp_verified',
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
