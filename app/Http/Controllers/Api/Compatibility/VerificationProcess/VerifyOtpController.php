<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /api/verification-process/verify/otp.
 *
 * Mobile sends: { trx: string, otp: string, remark: string }
 * Returns the legacy MaphaPay ActionResponse envelope.
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
        } catch (RuntimeException $e) {
            return response()->json([
                'status'  => 'error',
                'remark'  => $validated['remark'] ?? 'otp_verified',
                'message' => [$e->getMessage()],
            ], 422);
        }
    }
}
