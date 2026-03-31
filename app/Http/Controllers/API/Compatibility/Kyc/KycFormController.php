<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Kyc;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/kyc-form
 *
 * Returns the current KYC status for the authenticated user.
 *
 * Progressive KYC pattern: users are always allowed into the app.
 * KYC is prompted contextually when they try to use gated features
 * (send-money, MTN MoMo, etc. are protected by the kyc_approved middleware).
 *
 * Response shape:
 *   {
 *     status: 'success',
 *     data: {
 *       kyc_status,       // 'not_started' | 'pending' | 'in_review' | 'approved' | 'rejected' | 'expired'
 *       form_available,   // true = app should show KYC prompt; false = no action needed (approved/pending)
 *       can_skip,         // true = user may dismiss KYC and proceed to home screen
 *       message,
 *     }
 *   }
 */
class KycFormController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $status = $user->kyc_status ?? 'not_started';

        // form_available: true means "there is a KYC action the user could take".
        // approved/pending/in_review users have nothing left to do right now.
        $formAvailable = in_array($status, ['not_started', 'rejected', 'expired'], true);

        // Progressive KYC: users can always skip and access basic app features.
        // Feature-level gates (kyc_approved middleware) enforce limits where needed.
        $canSkip = true;

        $message = match ($status) {
            'approved'    => 'KYC verification complete.',
            'pending'     => 'Your verification is being reviewed.',
            'in_review'   => 'Your verification is under review.',
            'rejected'    => 'Your verification was rejected. Please resubmit to unlock full access.',
            'expired'     => 'Your verification has expired. Please resubmit to unlock full access.',
            default       => 'Verify your identity to unlock higher limits and all features.',
        };

        return response()->json([
            'status' => 'success',
            'data'   => [
                'kyc_status'     => $status,
                'form_available' => $formAvailable,
                'can_skip'       => $canSkip,
                'message'        => $message,
            ],
        ]);
    }
}
