<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HasApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;
use OpenApi\Attributes as OA;

class TwoFactorAuthController extends Controller
{
    use HasApiScopes;

    /**
     * Enable two-factor authentication for the user.
     */
    #[OA\Post(
        path: '/api/auth/2fa/enable',
        operationId: 'enable2FA',
        tags: ['Authentication'],
        summary: 'Enable two-factor authentication',
        description: 'Enable 2FA for the authenticated user',
        security: [['sanctum' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: '2FA enabled successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Two-factor authentication enabled successfully.'),
        new OA\Property(property: 'secret', type: 'string', example: 'JBSWY3DPEHPK3PXP'),
        new OA\Property(property: 'qr_code', type: 'string', example: 'data:image/png;base64,...'),
        new OA\Property(property: 'recovery_codes', type: 'array', items: new OA\Items(type: 'string')),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthenticated'
    )]
    public function enable(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $enable($authUser);

        /** @var User $user */
        $user = $authUser->fresh();

        return response()->json(
            [
                'message'        => 'Two-factor authentication enabled successfully.',
                'secret'         => decrypt((string) $user->two_factor_secret),
                'qr_code'        => $user->twoFactorQrCodeSvg(),
                'recovery_codes' => json_decode(decrypt((string) $user->two_factor_recovery_codes), true),
            ]
        );
    }

    /**
     * Confirm and finalize enabling two-factor authentication.
     */
    #[OA\Post(
        path: '/api/auth/2fa/confirm',
        operationId: 'confirm2FA',
        tags: ['Authentication'],
        summary: 'Confirm two-factor authentication',
        description: 'Confirm 2FA setup with verification code',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code'], properties: [
        new OA\Property(property: 'code', type: 'string', example: '123456'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: '2FA confirmed successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Two-factor authentication confirmed successfully.'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Invalid verification code'
    )]
    public function confirm(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (
            ! $user->two_factor_secret
            || ! $provider->verify(decrypt((string) $user->two_factor_secret), $request->code)
        ) {
            return response()->json(
                [
                    'message' => 'The provided two factor authentication code was invalid.',
                ],
                422
            );
        }

        $user->forceFill(
            [
                'two_factor_confirmed_at' => now(),
            ]
        )->save();

        return response()->json(
            [
                'message' => 'Two-factor authentication confirmed successfully.',
            ]
        );
    }

    /**
     * Disable two-factor authentication for the user.
     */
    #[OA\Post(
        path: '/api/auth/2fa/disable',
        operationId: 'disable2FA',
        tags: ['Authentication'],
        summary: 'Disable two-factor authentication',
        description: 'Disable 2FA for the authenticated user',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['password'], properties: [
        new OA\Property(property: 'password', type: 'string', format: 'password', example: 'current-password'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: '2FA disabled successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Two-factor authentication disabled successfully.'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Invalid password'
    )]
    public function disable(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        $request->validate(
            [
                'password' => 'required|string|current_password:sanctum',
            ]
        );

        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $disable($user);

        return response()->json(
            [
                'message' => 'Two-factor authentication disabled successfully.',
            ]
        );
    }

    /**
     * Verify two-factor authentication code.
     */
    #[OA\Post(
        path: '/api/auth/2fa/verify',
        operationId: 'verify2FA',
        tags: ['Authentication'],
        summary: 'Verify two-factor authentication code',
        description: 'Verify 2FA code during login',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code'], properties: [
        new OA\Property(property: 'code', type: 'string', example: '123456'),
        new OA\Property(property: 'recovery_code', type: 'string', example: 'recovery-code', description: 'Use recovery code instead of 2FA code'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: '2FA verified successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Two-factor authentication verified successfully.'),
        new OA\Property(property: 'token', type: 'string', example: '1|laravel_sanctum_token...'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Invalid code'
    )]
    public function verify(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $request->validate(
            [
                'code'          => 'required_without:recovery_code|string',
                'recovery_code' => 'required_without:code|string',
            ]
        );

        $user = $request->user();

        // Type assertion for PHPStan
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($request->has('recovery_code')) {
            $codes = $user->recoveryCodes();

            if (! in_array($request->recovery_code, $codes)) {
                return response()->json(
                    [
                        'message' => 'The provided recovery code was invalid.',
                    ],
                    422
                );
            }

            $user->replaceRecoveryCode($request->recovery_code);
        } else {
            if (! $provider->verify(decrypt((string) $user->two_factor_secret), $request->code)) {
                return response()->json(
                    [
                        'message' => 'The provided two factor authentication code was invalid.',
                    ],
                    422
                );
            }
        }

        // Generate new token with proper expiration after successful 2FA
        $token = $this->createTokenWithScopes($user, '2fa-verified-token');

        return response()->json(
            [
                'message' => 'Two-factor authentication verified successfully.',
                'token'   => $token,
            ]
        );
    }

    /**
     * Get new recovery codes.
     */
    #[OA\Post(
        path: '/api/auth/2fa/recovery-codes',
        operationId: 'regenerateRecoveryCodes',
        tags: ['Authentication'],
        summary: 'Regenerate recovery codes',
        description: 'Generate new set of recovery codes',
        security: [['sanctum' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Recovery codes regenerated',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Recovery codes regenerated successfully.'),
        new OA\Property(property: 'recovery_codes', type: 'array', items: new OA\Items(type: 'string')),
        ])
    )]
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user->forceFill(
            [
                'two_factor_recovery_codes' => encrypt(json_encode(collect()->times(8, fn () => RecoveryCode::generate())->toArray())),
            ]
        )->save();

        return response()->json(
            [
                'message'        => 'Recovery codes regenerated successfully.',
                'recovery_codes' => json_decode(decrypt((string) $user->two_factor_recovery_codes), true),
            ]
        );
    }
}
