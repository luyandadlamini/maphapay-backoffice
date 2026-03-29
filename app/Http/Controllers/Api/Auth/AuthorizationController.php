<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use RuntimeException;

class AuthorizationController extends Controller
{
    #[OA\Get(
        path: '/api/auth/authorization',
        summary: 'Get authorization/verification status',
        description: 'Returns pending verification steps for the authenticated user (sms, email, kyc)',
        operationId: 'getAuthorizationStatus',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Authorization status retrieved',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'type', type: 'string', example: 'mobile_verification'),
                    new OA\Property(property: 'status', type: 'string', example: 'pending'),
                    new OA\Property(property: 'label', type: 'string', example: 'Verify Mobile'),
                    new OA\Property(property: 'completed_at', type: 'string', nullable: true),
                ])),
                new OA\Property(property: 'overall_status', type: 'string', enum: ['pending', 'partial', 'complete']),
            ]),
        ])
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $steps = [];

        $steps[] = [
            'type'         => 'mobile_verification',
            'status'       => $user->mobile_verified_at ? 'complete' : 'pending',
            'label'        => 'Verify Mobile',
            'completed_at' => $user->mobile_verified_at?->toISOString(),
        ];

        $steps[] = [
            'type'         => 'email_verification',
            'status'       => $user->email_verified_at ? 'complete' : 'pending',
            'label'        => 'Verify Email',
            'completed_at' => $user->email_verified_at?->toISOString(),
        ];

        $steps[] = [
            'type'         => 'kyc',
            'status'       => $user->kyc_status === 'approved' ? 'complete' : ($user->kyc_status === 'pending' ? 'pending' : 'pending'),
            'label'        => 'KYC Verification',
            'completed_at' => $user->kyc_approved_at?->toISOString(),
        ];

        $completeCount = collect($steps)->where('status', 'complete')->count();
        $overallStatus = match (true) {
            $completeCount === count($steps) => 'complete',
            $completeCount > 0               => 'partial',
            default                          => 'pending',
        };

        return response()->json([
            'success' => true,
            'data'    => [
                'steps'          => $steps,
                'overall_status' => $overallStatus,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/auth/authorization/resend',
        summary: 'Resend verification code',
        description: 'Resends the verification code for a specific step type',
        operationId: 'resendAuthorizationCode',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['type'], properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['mobile_verification', 'email_verification']),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'Code resent',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    #[OA\Response(
        response: 400,
        description: 'Invalid type or step already complete',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:mobile_verification,email_verification',
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($validated['type'] === 'mobile_verification') {
            if ($user->mobile_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mobile already verified',
                ], 400);
            }

            $this->resendMobileVerification($user);
        }

        if ($validated['type'] === 'email_verification') {
            if ($user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email already verified',
                ], 400);
            }

            $user->sendEmailVerificationNotification();

            return response()->json([
                'success' => true,
                'message' => 'Verification email sent',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Code resent successfully',
        ]);
    }

    private function resendMobileVerification(User $user): void
    {
        $otpType = UserOtp::TYPE_MOBILE_VERIFICATION;

        $existingOtp = UserOtp::where('user_id', $user->id)
            ->where('type', $otpType)
            ->whereNull('verified_at')
            ->first();

        if ($existingOtp) {
            $canResend = now()->diffInSeconds($existingOtp->created_at) >= 120;
            if (! $canResend) {
                throw new RuntimeException('Please wait before requesting a new code');
            }
        }

        $otpService = app(\App\Domain\Shared\Services\OtpService::class);
        $otpService->generateAndSend($user, $otpType);
    }
}
