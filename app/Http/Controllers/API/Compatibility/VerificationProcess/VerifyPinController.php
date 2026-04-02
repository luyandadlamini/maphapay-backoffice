<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\InvalidTransactionPinException;
use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Exceptions\TransactionPinNotSetException;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trx'    => ['required', 'string'],
            'pin'    => ['required', 'string', 'digits:4'],
            'remark' => ['sometimes', 'string'],
        ]);

        try {
            $result = $this->manager->verifyPin(
                trx:    $validated['trx'],
                userId: (int) $request->user()?->getAuthIdentifier(),
                pin:    $validated['pin'],
            );

            return response()->json([
                'status' => 'success',
                'remark' => $validated['remark'] ?? 'pin_verified',
                'data'   => $result,
            ]);
        } catch (TransactionNotFoundException $e) {
            return $this->errorResponse(
                $validated['remark'] ?? 'pin_verified',
                $e->getMessage(),
                404,
            );
        } catch (TransactionPinNotSetException | InvalidTransactionPinException $e) {
            return $this->errorResponse(
                $validated['remark'] ?? 'pin_verified',
                $e->getMessage(),
                422,
            );
        } catch (RuntimeException $e) {
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
            'status'  => 'error',
            'remark'  => $remark,
            'message' => [$message],
            'data'    => null,
        ], $status);
    }
}
