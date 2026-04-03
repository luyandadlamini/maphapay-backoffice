<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionBiometricService;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class VerifyBiometricController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionBiometricService $biometricService,
        private readonly AuthorizedTransactionManager $manager,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trx' => ['required', 'string'],
            'device_id' => ['required', 'string'],
            'challenge' => ['required', 'string'],
            'signature' => ['required', 'string'],
            'remark' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            $remark = (string) $request->input('remark', 'biometric_verified');
            $message = (string) ($validator->errors()->first() ?: 'Invalid verification request.');

            $this->telemetry->logVerificationFailure(
                $request,
                'biometric',
                $remark,
                is_string($request->input('trx')) ? $request->input('trx') : null,
                $message,
                422,
                $this->findTransaction($request, is_string($request->input('trx')) ? $request->input('trx') : null),
            );

            return $this->errorResponse($remark, $message, 422);
        }

        /** @var array{trx: string, device_id: string, challenge: string, signature: string, remark?: string} $validated */
        $validated = $validator->validated();

        try {
            $this->biometricService->verifyChallengeForUser(
                trx: $validated['trx'],
                userId: (int) $request->user()?->getAuthIdentifier(),
                deviceId: $validated['device_id'],
                challenge: $validated['challenge'],
                signature: $validated['signature'],
                remark: $validated['remark'] ?? null,
                ipAddress: $request->ip(),
            );

            $result = $this->manager->verifyBiometric(
                trx: $validated['trx'],
                userId: (int) $request->user()?->getAuthIdentifier(),
            );

            $transaction = $this->findTransaction($request, $validated['trx']);

            $this->telemetry->logEvent('verification_succeeded', $this->telemetry->requestContext($request, [
                'verification_method' => 'biometric',
                'remark' => $validated['remark'] ?? 'biometric_verified',
                'trx' => $validated['trx'],
            ] + $this->telemetry->transactionContext($transaction)));

            return response()->json([
                'status' => 'success',
                'remark' => $validated['remark'] ?? 'biometric_verified',
                'data' => $result,
            ]);
        } catch (TransactionNotFoundException $e) {
            $this->telemetry->logVerificationFailure(
                $request,
                'biometric',
                $validated['remark'] ?? 'biometric_verified',
                $validated['trx'],
                $e->getMessage(),
                404,
                $this->findTransaction($request, $validated['trx']),
            );

            return $this->errorResponse(
                $validated['remark'] ?? 'biometric_verified',
                $e->getMessage(),
                404,
            );
        } catch (RuntimeException $e) {
            $this->telemetry->logVerificationFailure(
                $request,
                'biometric',
                $validated['remark'] ?? 'biometric_verified',
                $validated['trx'],
                $e->getMessage(),
                422,
                $this->findTransaction($request, $validated['trx']),
            );

            return $this->errorResponse(
                $validated['remark'] ?? 'biometric_verified',
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
