<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate money-moving compat endpoints behind KYC approval.
 *
 * The User model's hasCompletedKyc() returns true only when
 * kyc_status = 'approved' and the approval has not expired.
 *
 * Usage:  ->middleware('kyc_approved')
 *
 * Returns 403 with a stable error envelope so the mobile app can
 * surface a "complete KYC" prompt rather than a generic error.
 */
class CheckKycApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasCompletedKyc()) {
            return response()->json([
                'message' => 'KYC verification required to perform this action.',
                'error'   => 'kyc_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
