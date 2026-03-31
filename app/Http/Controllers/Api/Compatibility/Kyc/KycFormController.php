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
 * The mobile app polls this endpoint after login to determine whether
 * to show the KYC form or proceed to the home screen.
 *
 * Response shape mirrors the legacy MaphaPay backend:
 *   { status: 'success', data: { kyc_status, form_available } }
 */
class KycFormController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'kyc_status'     => $user->kyc_status,
                'form_available' => false,
                'message'        => match ($user->kyc_status) {
                    'approved'  => 'KYC verification complete.',
                    'pending'   => 'Your verification is being processed.',
                    'in_review' => 'Your verification is under review.',
                    'rejected'  => 'Your verification was rejected. Please resubmit.',
                    'expired'   => 'Your verification has expired. Please resubmit.',
                    default     => 'Identity verification required.',
                },
            ],
        ]);
    }
}
