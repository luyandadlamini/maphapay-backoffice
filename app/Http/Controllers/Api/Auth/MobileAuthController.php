<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Shared\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use RuntimeException;

class MobileAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {
    }

    #[OA\Post(
        path: '/api/auth/mobile/login',
        summary: 'Mobile/PIN login with auto-registration',
        description: 'Login with mobile number and dial code. Auto-registers if user not found and sends OTP.',
        operationId: 'mobileLogin',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['mobile', 'dial_code'], properties: [
            new OA\Property(property: 'dial_code', type: 'string', example: '+268'),
            new OA\Property(property: 'mobile', type: 'string', example: '76123456'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'OTP sent - awaiting verification',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string', example: 'OTP sent to your mobile number'),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'user', type: 'object'),
                new OA\Property(property: 'otp_sent', type: 'boolean', example: true),
            ]),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'errors', type: 'object'),
        ])
    )]
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dial_code' => 'required|string|max:10',
            'mobile'    => 'required|string|max=20',
        ]);

        $dialCode = $validated['dial_code'];
        $mobile = $validated['mobile'];

        $user = User::where('dial_code', $dialCode)
            ->where('mobile', $mobile)
            ->first();

        $isNewUser = false;

        if (! $user) {
            $isNewUser = true;
            $user = DB::transaction(function () use ($dialCode, $mobile) {
                return User::create([
                    'name'       => '',
                    'email'      => null,
                    'password'   => Hash::make(Str::random(32)),
                    'dial_code'  => $dialCode,
                    'mobile'     => $mobile,
                    'kyc_status' => 'not_started',
                ]);
            });
        }

        $this->otpService->generateAndSend($user, UserOtp::TYPE_LOGIN);

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your mobile number',
            'data'    => [
                'user'        => $this->transformUser($user),
                'otp_sent'    => true,
                'is_new_user' => $isNewUser,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/auth/mobile/verify-otp',
        summary: 'Verify OTP and obtain access token',
        description: 'Verifies the OTP sent to the user mobile and returns access/refresh tokens',
        operationId: 'verifyOtp',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['mobile', 'dial_code', 'otp'], properties: [
            new OA\Property(property: 'dial_code', type: 'string', example: '+268'),
            new OA\Property(property: 'mobile', type: 'string', example: '76123456'),
            new OA\Property(property: 'otp', type: 'string', example: '123456'),
            new OA\Property(property: 'device_name', type: 'string', example: 'iPhone 12'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'OTP verified - access token returned',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'user', type: 'object'),
                new OA\Property(property: 'access_token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string'),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
            ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Invalid or expired OTP',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'message', type: 'string', example: 'Invalid or expired OTP'),
        ])
    )]
    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dial_code'   => 'required|string|max:10',
            'mobile'      => 'required|string|max:20',
            'otp'         => 'required|string|size:6',
            'device_name' => 'string',
        ]);

        $user = User::where('dial_code', $validated['dial_code'])
            ->where('mobile', $validated['mobile'])
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 401);
        }

        $verified = $this->otpService->verify($user, UserOtp::TYPE_LOGIN, $validated['otp']);

        if (! $verified) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ], 401);
        }

        $user->update(['mobile_verified_at' => now()]);

        $tokenPair = $this->createTokenPair($user, $validated['device_name'] ?? 'mobile');

        return response()->json([
            'success' => true,
            'data'    => [
                'user'          => $this->transformUser($user),
                'access_token'  => $tokenPair['access_token'],
                'refresh_token' => $tokenPair['refresh_token'],
                'token_type'    => 'Bearer',
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/auth/mobile/resend-otp',
        summary: 'Resend OTP',
        description: 'Resends the OTP to the user mobile',
        operationId: 'resendOtp',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['mobile', 'dial_code'], properties: [
            new OA\Property(property: 'dial_code', type: 'string', example: '+268'),
            new OA\Property(property: 'mobile', type: 'string', example: '76123456'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'OTP resent',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'can_resend', type: 'boolean'),
                new OA\Property(property: 'remaining_seconds', type: 'integer'),
            ]),
        ])
    )]
    #[OA\Response(
        response: 429,
        description: 'Cooldown not elapsed',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dial_code' => 'required|string|max:10',
            'mobile'    => 'required|string|max:20',
        ]);

        $user = User::where('dial_code', $validated['dial_code'])
            ->where('mobile', $validated['mobile'])
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 401);
        }

        try {
            $this->otpService->resend($user, UserOtp::TYPE_LOGIN);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 429);
        }

        $canResend = $this->otpService->canResend($user, UserOtp::TYPE_LOGIN);

        return response()->json([
            'success' => true,
            'message' => 'OTP resent to your mobile number',
            'data'    => $canResend,
        ]);
    }

    #[OA\Post(
        path: '/api/auth/mobile/forgot-pin',
        summary: 'Initiate PIN reset',
        description: 'Sends a reset OTP to the user mobile for PIN reset',
        operationId: 'forgotPin',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['mobile', 'dial_code'], properties: [
            new OA\Property(property: 'dial_code', type: 'string', example: '+268'),
            new OA\Property(property: 'mobile', type: 'string', example: '76123456'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'Reset OTP sent',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    #[OA\Response(
        response: 404,
        description: 'User not found',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    public function forgotPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dial_code' => 'required|string|max:10',
            'mobile'    => 'required|string|max:20',
        ]);

        $user = User::where('dial_code', $validated['dial_code'])
            ->where('mobile', $validated['mobile'])
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $this->otpService->generateAndSend($user, UserOtp::TYPE_PIN_RESET);

        return response()->json([
            'success' => true,
            'message' => 'Reset code sent to your mobile number',
        ]);
    }

    #[OA\Post(
        path: '/api/auth/mobile/verify-reset-code',
        summary: 'Verify PIN reset code',
        description: 'Verifies the OTP sent for PIN reset',
        operationId: 'verifyResetCode',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['mobile', 'dial_code', 'otp'], properties: [
            new OA\Property(property: 'dial_code', type: 'string', example: '+268'),
            new OA\Property(property: 'mobile', type: 'string', example: '76123456'),
            new OA\Property(property: 'otp', type: 'string', example: '123456'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'Reset code verified',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Invalid or expired reset code',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    public function verifyResetCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dial_code' => 'required|string|max:10',
            'mobile'    => 'required|string|max:20',
            'otp'       => 'required|string|size:6',
        ]);

        $user = User::where('dial_code', $validated['dial_code'])
            ->where('mobile', $validated['mobile'])
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 401);
        }

        $verified = $this->otpService->verify($user, UserOtp::TYPE_PIN_RESET, $validated['otp']);

        if (! $verified) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset code',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reset code verified',
        ]);
    }

    #[OA\Post(
        path: '/api/auth/mobile/reset-pin',
        summary: 'Reset PIN',
        description: 'Resets the user PIN after verified reset code',
        operationId: 'resetPin',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['mobile', 'dial_code', 'otp', 'pin'], properties: [
            new OA\Property(property: 'dial_code', type: 'string', example: '+268'),
            new OA\Property(property: 'mobile', type: 'string', example: '76123456'),
            new OA\Property(property: 'otp', type: 'string', example: '123456'),
            new OA\Property(property: 'pin', type: 'string', minLength: 4, maxLength: 6, example: '1234'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'PIN reset successful',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Invalid or expired reset code',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    public function resetPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dial_code' => 'required|string|max:10',
            'mobile'    => 'required|string|max:20',
            'otp'       => 'required|string|size:6',
            'pin'       => 'required|string|min:4|max:6',
        ]);

        $user = User::where('dial_code', $validated['dial_code'])
            ->where('mobile', $validated['mobile'])
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 401);
        }

        $verified = $this->otpService->verify($user, UserOtp::TYPE_PIN_RESET, $validated['otp']);

        if (! $verified) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset code',
            ], 401);
        }

        $user->update(['transaction_pin' => Hash::make($validated['pin'])]);

        return response()->json([
            'success' => true,
            'message' => 'PIN reset successfully',
        ]);
    }

    #[OA\Post(
        path: '/api/auth/mobile/complete-profile',
        summary: 'Complete user profile after mobile verification',
        description: 'Updates user profile with name, email, username after mobile is verified',
        operationId: 'completeProfile',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'email'], properties: [
            new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
            new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'Profile completed',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'user', type: 'object'),
            ]),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error or mobile not verified',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'errors', type: 'object'),
        ])
    )]
    public function completeProfile(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email,' . $authUser->id,
            'username' => 'nullable|string|min:3|max:30|unique:users,username,' . $authUser->id,
        ]);

        /** @var User $user */
        $user = $authUser;

        if (! $user->mobile_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Mobile number must be verified before completing profile',
            ], 422);
        }

        $user->update([
            'name'                     => $validated['name'],
            'email'                    => $validated['email'],
            'username'                 => $validated['username'] ?? null,
            'has_completed_onboarding' => true,
            'onboarding_completed_at'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'user' => $this->transformUser($user),
            ],
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, refresh_expires_in: int}
     */
    private function createTokenPair(User $user, string $deviceName): array
    {
        $accessToken = $user->createToken($deviceName, ['read', 'write']);
        $refreshToken = $user->createToken($deviceName . '-refresh', ['refresh']);

        return [
            'access_token'       => $accessToken->plainTextToken,
            'refresh_token'      => $refreshToken->plainTextToken,
            'expires_in'         => config('sanctum.expiration', 86400),
            'refresh_expires_in' => config('sanctum.refresh_token_expiration', 2592000),
        ];
    }

    /**
     * @return array{id: int, uuid: string, name: ?string, email: ?string, username: ?string, mobile: ?string, dial_code: ?string, mobile_verified_at: ?string, kyc_status: ?string, has_completed_onboarding: bool}
     */
    private function transformUser(User $user): array
    {
        return [
            'id'                       => $user->id,
            'uuid'                     => $user->uuid,
            'name'                     => $user->name,
            'email'                    => $user->email,
            'username'                 => $user->username,
            'mobile'                   => $user->mobile,
            'dial_code'                => $user->dial_code,
            'mobile_verified_at'       => $user->mobile_verified_at?->toISOString(),
            'kyc_status'               => $user->kyc_status,
            'has_completed_onboarding' => $user->has_completed_onboarding,
        ];
    }
}
