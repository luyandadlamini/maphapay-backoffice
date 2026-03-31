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
 * Returns the legacy MaphaPay ActionResponse envelope.
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
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 404);
        } catch (TransactionPinNotSetException | InvalidTransactionPinException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json([
                'status'  => 'error',
                'remark'  => $validated['remark'] ?? 'pin_verified',
                'message' => [$e->getMessage()],
            ], 422);
        }
    }
}
