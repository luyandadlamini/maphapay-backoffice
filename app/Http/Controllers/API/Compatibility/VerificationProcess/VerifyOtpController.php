<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
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
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trx'    => ['required', 'string'],
            'otp'    => ['required', 'string', 'digits:6'],
            'remark' => ['sometimes', 'string'],
        ]);

        try {
            $result = $this->manager->verifyOtp(
                trx:    $validated['trx'],
                userId: (int) $request->user()?->getAuthIdentifier(),
                otp:    $validated['otp'],
            );

            return response()->json([
                'status' => 'success',
                'remark' => $validated['remark'] ?? 'otp_verified',
                'data'   => $result,
            ]);
        } catch (TransactionNotFoundException $e) {
            return $this->errorResponse(
                $validated['remark'] ?? 'otp_verified',
                $e->getMessage(),
                404,
            );
        } catch (RuntimeException $e) {
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
            'status'  => 'error',
            'remark'  => $remark,
            'message' => [$message],
            'data'    => null,
        ], $status);
    }
}
