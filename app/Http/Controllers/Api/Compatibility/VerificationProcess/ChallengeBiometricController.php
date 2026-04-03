<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionBiometricService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ChallengeBiometricController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionBiometricService $biometricService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trx' => ['required', 'string'],
            'device_id' => ['required', 'string'],
            'remark' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            $message = (string) ($validator->errors()->first() ?: 'Invalid verification request.');

            return $this->errorResponse(
                (string) $request->input('remark', 'biometric_challenge'),
                $message,
                422,
            );
        }

        /** @var array{trx: string, device_id: string, remark?: string} $validated */
        $validated = $validator->validated();

        try {
            $challenge = $this->biometricService->issueChallengeForUser(
                trx: $validated['trx'],
                userId: (int) $request->user()?->getAuthIdentifier(),
                deviceId: $validated['device_id'],
                remark: $validated['remark'] ?? null,
                ipAddress: $request->ip(),
            );

            return response()->json([
                'status' => 'success',
                'remark' => $validated['remark'] ?? 'biometric_challenge',
                'data' => [
                    'trx' => $validated['trx'],
                    'device_id' => $validated['device_id'],
                    'challenge' => $challenge->challenge,
                    'expires_at' => $challenge->expires_at->toIso8601String(),
                ],
            ]);
        } catch (TransactionNotFoundException $e) {
            return $this->errorResponse(
                $validated['remark'] ?? 'biometric_challenge',
                $e->getMessage(),
                404,
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $validated['remark'] ?? 'biometric_challenge',
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
}
