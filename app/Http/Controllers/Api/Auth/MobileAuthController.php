<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Account\Services\AccountPayloadTransformer;
use App\Domain\Onboarding\Services\DefaultUserResourceProvisioningService;
use App\Domain\Shared\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use RuntimeException;

class MobileAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly DefaultUserResourceProvisioningService $defaultUserResourceProvisioningService,
        private readonly AccountPayloadTransformer $accountPayloadTransformer,
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
            'dial_code'     => 'required|string|max:10',
            'mobile'        => 'nullable|string|max:20|required_without:mobile_number',
            'mobile_number' => 'nullable|string|max:20|required_without:mobile',
            'pin'           => 'sometimes|nullable|string|min:4|max:6',
            'device_name'   => 'sometimes|string',
            'skip_otp_send' => 'sometimes|boolean',
        ]);

        $dialCode = self::normalizeDialCode($validated['dial_code']);
        $mobile = self::normalizeMobileLocalPart(
            (string) ($validated['mobile'] ?? $validated['mobile_number'] ?? '')
        );
        $pin = trim((string) ($validated['pin'] ?? ''));

        Log::info('Mobile login attempt', [
            'dial_code' => $dialCode,
            'mobile'    => $mobile,
            'has_pin'   => ! empty($pin),
            'ip'        => $request->ip(),
        ]);

        $user = User::where('dial_code', $dialCode)
            ->where('mobile', $mobile)
            ->first();

        Log::info('Mobile login user lookup', [
            'dial_code'           => $dialCode,
            'mobile'              => $mobile,
            'user_found'          => $user !== null,
            'user_id'             => $user?->id,
            'has_transaction_pin' => ! empty($user?->transaction_pin),
            'has_password'        => ! empty($user?->password),
        ]);

        if ($pin !== '') {
            $pinMatchesTransactionPin =
                $user
                && is_string($user->transaction_pin)
                && $user->transaction_pin !== ''
                && Hash::check($pin, $user->transaction_pin);

            $pinMatchesLegacyPassword =
                $user
                && (! is_string($user->transaction_pin) || $user->transaction_pin === '')
                && is_string($user->password)
                && $user->password !== ''
                && Hash::check($pin, $user->password);

            Log::info('Mobile login PIN check', [
                'user_id'                     => $user?->id,
                'pin_matches_transaction_pin' => $pinMatchesTransactionPin,
                'pin_matches_legacy_password' => $pinMatchesLegacyPassword,
            ]);

            if (
                ! $user
                || (! $pinMatchesTransactionPin && ! $pinMatchesLegacyPassword)
            ) {
                Log::warning('Mobile login failed - invalid credentials', [
                    'dial_code'   => $dialCode,
                    'mobile'      => $mobile,
                    'user_exists' => $user !== null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'The provided credentials are incorrect.',
                ], 401);
            }

            if ($pinMatchesLegacyPassword) {
                User::whereKey($user->id)->update([
                    'transaction_pin'         => $user->getRawOriginal('password'),
                    'transaction_pin_enabled' => true,
                ]);
                $user->refresh();
            }

            if ($user->mobile_verified_at === null) {
                $user->update(['mobile_verified_at' => now()]);
            }

            $tokenPair       = $this->createTokenPair($user, $validated['device_name'] ?? 'mobile');
            $this->enforceSessionLimits($user, $tokenPair['newly_created_token_ids']);
            $accounts        = $this->transformAccountMemberships($user);
            $activeAccountId = $this->resolveActiveAccountId($user);

            return response()->json([
                'success' => true,
                'remark'  => 'login_success',
                'data'    => [
                    'user'               => $this->transformUser($user),
                    'accounts'           => $accounts,
                    'active_account_id'  => $activeAccountId,
                    'access_token'       => $tokenPair['access_token'],
                    'refresh_token'      => $tokenPair['refresh_token'],
                    'token_type'         => 'Bearer',
                    'expires_in'         => $tokenPair['expires_in'],
                    'refresh_expires_in' => $tokenPair['refresh_expires_in'],
                ],
            ]);
        }

        $isNewUser = false;

        if (! $user) {
            $user = DB::transaction(function () use ($dialCode, $mobile) {
                return User::firstOrCreate([
                    'dial_code' => $dialCode,
                    'mobile'    => $mobile,
                ], [
                    'name'       => '',
                    'email'      => null,
                    'password'   => Hash::make(Str::random(32)),
                    'kyc_status' => 'not_started',
                ]);
            });

            $isNewUser = $user->wasRecentlyCreated;
        }

        if (
            $request->boolean('skip_otp_send')
            && (bool) config('otp.allow_skip_send_on_register', false)
        ) {
            return response()->json([
                'success' => true,
                'remark'  => 'mobile_verification_required',
                'message' => 'Continue with OTP verification.',
                'data'    => [
                    'user'        => $this->transformUser($user),
                    'otp_sent'    => false,
                    'is_new_user' => $isNewUser,
                ],
            ]);
        }

        try {
            $this->otpService->generateAndSend($user, UserOtp::TYPE_LOGIN);
        } catch (RuntimeException $e) {
            if ($isNewUser && $user->mobile_verified_at === null && ! $user->has_completed_onboarding) {
                $user->delete();
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'remark'  => 'mobile_verification_required',
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
            'dial_code'     => 'required|string|max:10',
            'mobile'        => 'nullable|string|max:20|required_without:mobile_number',
            'mobile_number' => 'nullable|string|max:20|required_without:mobile',
            'otp'           => 'required|string|max:32',
            'device_name'   => 'sometimes|string',
        ]);

        $dialCode = self::normalizeDialCode($validated['dial_code']);
        $mobile = self::normalizeMobileLocalPart(
            (string) ($validated['mobile'] ?? $validated['mobile_number'] ?? '')
        );
        $otpDigits = preg_replace('/\D+/', '', $validated['otp']) ?? '';

        if (strlen($otpDigits) !== 6) {
            throw ValidationException::withMessages([
                'otp' => ['The OTP must be 6 digits.'],
            ]);
        }

        Log::info('MobileAuthController: verifyOtp called', [
            'dial_code' => $dialCode,
            'mobile'    => $mobile,
            'otp'       => $otpDigits,
        ]);

        $user = User::where('dial_code', $dialCode)
            ->where('mobile', $mobile)
            ->first();

        Log::info('MobileAuthController: user lookup', [
            'user_found' => $user !== null,
            'user_id'    => $user?->id,
        ]);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ], 401);
        }

        $verified = $this->otpService->verify($user, UserOtp::TYPE_LOGIN, $otpDigits);

        Log::info('MobileAuthController: OTP verification result', [
            'verified' => $verified,
        ]);

        if (! $verified) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ], 401);
        }

        $user->update(['mobile_verified_at' => now()]);

        $tokenPair = $this->createTokenPair($user, $validated['device_name'] ?? 'mobile');
        $this->enforceSessionLimits($user, $tokenPair['newly_created_token_ids']);

        Log::info('MobileAuthController: verifyOtp success', [
            'user_id'                  => $user->id,
            'mobile_verified_at'       => $user->mobile_verified_at?->toISOString(),
            'kyc_status'               => $user->kyc_status,
            'has_completed_onboarding' => $user->has_completed_onboarding,
            'transaction_pin_set'      => $user->transaction_pin_set,
            'transaction_pin_enabled'  => $user->transaction_pin_enabled,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'user'              => $this->transformUser($user),
                'accounts'          => $this->transformAccountMemberships($user),
                'active_account_id' => $this->resolveActiveAccountId($user),
                'access_token'      => $tokenPair['access_token'],
                'refresh_token'     => $tokenPair['refresh_token'],
                'token_type'        => 'Bearer',
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
            'dial_code'     => 'required|string|max:10',
            'mobile'        => 'nullable|string|max:20|required_without:mobile_number',
            'mobile_number' => 'nullable|string|max:20|required_without:mobile',
        ]);

        $dialCode = self::normalizeDialCode($validated['dial_code']);
        $mobile = self::normalizeMobileLocalPart(
            (string) ($validated['mobile'] ?? $validated['mobile_number'] ?? '')
        );

        $user = User::where('dial_code', $dialCode)
            ->where('mobile', $mobile)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => true,
                'message' => 'If the account exists, a new OTP has been sent.',
                'data'    => ['can_resend' => true, 'remaining_seconds' => 0],
            ]);
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
            'dial_code'     => 'required|string|max:10',
            'mobile'        => 'nullable|string|max:20|required_without:mobile_number',
            'mobile_number' => 'nullable|string|max:20|required_without:mobile',
        ]);

        $dialCode = self::normalizeDialCode($validated['dial_code']);
        $mobile = self::normalizeMobileLocalPart(
            (string) ($validated['mobile'] ?? $validated['mobile_number'] ?? '')
        );

        Log::info('Forgot PIN request', [
            'dial_code' => $dialCode,
            'mobile'    => $mobile,
            'ip'        => $request->ip(),
        ]);

        $user = User::where('dial_code', $dialCode)
            ->where('mobile', $mobile)
            ->first();

        Log::info('Forgot PIN user lookup', [
            'dial_code'  => $dialCode,
            'mobile'     => $mobile,
            'user_found' => $user !== null,
            'user_id'    => $user?->id,
        ]);

        if (! $user) {
            Log::info('Forgot PIN - user not found (returns generic message)', [
                'dial_code' => $dialCode,
                'mobile'    => $mobile,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'If the account exists, a reset code has been sent to the mobile number.',
            ]);
        }

        try {
            $this->otpService->generateAndSend($user, UserOtp::TYPE_PIN_RESET);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }

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
            'dial_code'     => 'required|string|max:10',
            'mobile'        => 'nullable|string|max:20|required_without:mobile_number',
            'mobile_number' => 'nullable|string|max:20|required_without:mobile',
            'otp'           => 'required|string|max:32',
        ]);

        $dialCode = self::normalizeDialCode($validated['dial_code']);
        $mobile = self::normalizeMobileLocalPart(
            (string) ($validated['mobile'] ?? $validated['mobile_number'] ?? '')
        );
        $otpDigits = preg_replace('/\D+/', '', $validated['otp']) ?? '';

        if (strlen($otpDigits) !== 6) {
            throw ValidationException::withMessages([
                'otp' => ['The reset code must be 6 digits.'],
            ]);
        }

        $user = User::where('dial_code', $dialCode)
            ->where('mobile', $mobile)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset code',
            ], 401);
        }

        $verified = $this->otpService->verify($user, UserOtp::TYPE_PIN_RESET, $otpDigits);

        if (! $verified) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset code',
            ], 401);
        }

        // Issue a short-lived reset grant so reset-pin does not need to re-verify the OTP.
        // The OTP is now consumed (verified_at set); the grant is the only valid proof.
        $grant = Str::random(64);
        Cache::put(
            'pin_reset_grant:' . $user->id . ':' . hash('sha256', $grant),
            true,
            now()->addMinutes(10),
        );

        return response()->json([
            'success' => true,
            'message' => 'Reset code verified',
            'data'    => ['reset_grant' => $grant],
        ]);
    }

    #[OA\Post(
        path: '/api/auth/mobile/reset-pin',
        summary: 'Reset PIN',
        description: 'Resets the user PIN using the reset grant issued by verify-reset-code',
        operationId: 'resetPin',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['mobile', 'dial_code', 'reset_grant', 'pin'], properties: [
            new OA\Property(property: 'dial_code', type: 'string', example: '+268'),
            new OA\Property(property: 'mobile', type: 'string', example: '76123456'),
            new OA\Property(property: 'reset_grant', type: 'string', description: 'Token returned by verify-reset-code'),
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
        description: 'Invalid or expired reset grant',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    public function resetPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dial_code'     => 'required|string|max:10',
            'mobile'        => 'nullable|string|max:20|required_without:mobile_number',
            'mobile_number' => 'nullable|string|max:20|required_without:mobile',
            'reset_grant'   => 'required|string|size:64',
            'pin'           => 'required|string|min:4|max:6|confirmed',
        ]);

        $dialCode = self::normalizeDialCode($validated['dial_code']);
        $mobile = self::normalizeMobileLocalPart(
            (string) ($validated['mobile'] ?? $validated['mobile_number'] ?? '')
        );

        $user = User::where('dial_code', $dialCode)
            ->where('mobile', $mobile)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset grant',
            ], 401);
        }

        $grantKey = 'pin_reset_grant:' . $user->id . ':' . hash('sha256', $validated['reset_grant']);

        if (! Cache::has($grantKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset grant',
            ], 401);
        }

        Cache::forget($grantKey);

        $user->update([
            'transaction_pin'         => Hash::make($validated['pin']),
            'transaction_pin_enabled' => true,
        ]);

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
            'name'             => 'nullable|string|max:255',
            'firstname'        => 'nullable|string|max:120|required_without:name',
            'lastname'         => 'nullable|string|max:120|required_without:name',
            'email'            => 'required|email|unique:users,email,' . $authUser->id,
            'username'         => 'nullable|string|min:3|max:30|unique:users,username,' . $authUser->id,
            'pin'              => 'required|string|min:4|max:6|confirmed',
            'pin_confirmation' => 'required|string|min:4|max:6',
        ]);

        /** @var User $user */
        $user = $authUser;

        if (! $user->mobile_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Mobile number must be verified before completing profile',
            ], 422);
        }

        $fullName = trim((string) ($validated['name'] ?? ''));

        if ($fullName === '') {
            $fullName = trim(sprintf(
                '%s %s',
                (string) ($validated['firstname'] ?? ''),
                (string) ($validated['lastname'] ?? ''),
            ));
        }

        $user->update([
            'name'                     => $fullName,
            'email'                    => $validated['email'],
            'username'                 => $validated['username'] ?? null,
            'transaction_pin'          => Hash::make($validated['pin']),
            'transaction_pin_enabled'  => true,
            'has_completed_onboarding' => true,
            'onboarding_completed_at'  => now(),
        ]);

        $this->defaultUserResourceProvisioningService->ensureForUser($user);

        return response()->json([
            'success' => true,
            'data'    => [
                'user' => $this->transformUser($user),
            ],
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, refresh_expires_in: int, newly_created_token_ids: array<int>}
     */
    private function createTokenPair(User $user, string $deviceName): array
    {
        $accessToken = $user->createToken($deviceName, ['read', 'write']);
        $refreshToken = $user->createToken($deviceName . '-refresh', ['refresh']);

        $plainAccessToken = $accessToken->plainTextToken;
        $plainRefreshToken = $refreshToken->plainTextToken;

        $newlyCreatedTokenIds = array_filter([
            $accessToken->accessToken->id ?? null,
            $refreshToken->accessToken->id ?? null,
        ]);

        return [
            'access_token'            => $plainAccessToken,
            'refresh_token'           => $plainRefreshToken,
            'expires_in'              => config('sanctum.expiration', 86400),
            'refresh_expires_in'      => config('sanctum.refresh_token_expiration', 2592000),
            'newly_created_token_ids' => array_values($newlyCreatedTokenIds),
        ];
    }

    /**
     * @return array{id: int, uuid: string, name: ?string, firstname: ?string, lastname: ?string, email: ?string, username: ?string, mobile: ?string, dial_code: ?string, mobile_verified_at: ?string, kyc_status: ?string, has_completed_onboarding: bool}
     */
    private function transformUser(User $user): array
    {
        [$firstName, $lastName] = $this->splitName($user->name);

        return [
            'id'                       => $user->id,
            'uuid'                     => $user->uuid,
            'name'                     => $user->name,
            'firstname'                => $firstName,
            'lastname'                 => $lastName,
            'email'                    => $user->email,
            'username'                 => $user->username,
            'mobile'                   => $user->mobile,
            'dial_code'                => $user->dial_code,
            'mobile_verified_at'       => $user->mobile_verified_at?->toISOString(),
            'kyc_status'               => $user->kyc_status,
            'transaction_pin_set'      => $user->transaction_pin_set,
            'transaction_pin_enabled'  => $user->transaction_pin_enabled,
            'has_completed_onboarding' => $user->has_completed_onboarding,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transformAccountMemberships(User $user): array
    {
        return $this->accountPayloadTransformer->transformUserMemberships($user);
    }

    private function resolveActiveAccountId(User $user): ?string
    {
        return $this->accountPayloadTransformer->resolveActiveAccountUuid($user);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(?string $name): array
    {
        $trimmed = trim((string) $name);

        if ($trimmed === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];
        $firstName = array_shift($parts);
        $lastName = $parts !== [] ? implode(' ', $parts) : null;

        return [$firstName ?: null, $lastName ?: null];
    }

    /**
     * @param array<int> $newlyCreatedTokenIds Token IDs to exclude from deletion (just created in this request)
     */
    private function enforceSessionLimits(User $user, array $newlyCreatedTokenIds = []): void
    {
        $maxSessions = (int) config('auth.max_concurrent_sessions', 5);

        $query = $user->tokens()->where('abilities', '!=', '["refresh"]');

        if ($newlyCreatedTokenIds !== []) {
            $query->whereNotIn('id', $newlyCreatedTokenIds);
        }

        $accessTokenCount = $query->count();

        if ($accessTokenCount <= $maxSessions) {
            return;
        }

        $tokensToDelete = $accessTokenCount - $maxSessions;

        $deleteQuery = $user->tokens()
            ->where('abilities', '!=', '["refresh"]');

        if ($newlyCreatedTokenIds !== []) {
            $deleteQuery->whereNotIn('id', $newlyCreatedTokenIds);
        }

        $deleteQuery->orderBy('created_at', 'asc')
            ->limit($tokensToDelete)
            ->delete();
    }

    private static function normalizeDialCode(string $dialCode): string
    {
        $dial = trim(str_replace(' ', '', $dialCode));

        if ($dial === '') {
            return $dial;
        }

        return str_starts_with($dial, '+') ? $dial : '+' . ltrim($dial, '+');
    }

    private static function normalizeMobileLocalPart(string $mobile): string
    {
        $digits = str_replace([' ', '-', '(', ')'], '', trim($mobile));
        if ($digits !== '' && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }
}
