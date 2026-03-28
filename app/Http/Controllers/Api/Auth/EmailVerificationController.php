<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class EmailVerificationController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    #[OA\Get(
        path: '/api/auth/verify-email/{id}/{hash}',
        operationId: 'verifyEmail',
        tags: ['Authentication'],
        summary: 'Verify email address',
        description: 'Verify user\'s email address using verification link',
        parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'User ID', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'hash', in: 'path', required: true, description: 'Verification hash', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'expires', in: 'query', required: true, description: 'Expiration timestamp', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'signature', in: 'query', required: true, description: 'URL signature', schema: new OA\Schema(type: 'string')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Email verified successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Email verified successfully.'),
        ])
    )]
    #[OA\Response(
        response: 403,
        description: 'Invalid or expired verification link'
    )]
    public function verify(Request $request, mixed $id, mixed $hash): \Illuminate\Http\JsonResponse
    {
        /** @var \Illuminate\Contracts\Auth\MustVerifyEmail&User $user */
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email verified successfully.']);
    }

    /**
     * Resend the email verification notification.
     */
    #[OA\Post(
        path: '/api/auth/resend-verification',
        operationId: 'resendVerification',
        tags: ['Authentication'],
        summary: 'Resend verification email',
        description: 'Resend email verification link to authenticated user',
        security: [['sanctum' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Verification link sent',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Verification link sent.'),
        ])
    )]
    #[OA\Response(
        response: 400,
        description: 'Email already verified',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Email already verified.'),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthenticated'
    )]
    public function resend(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var \Illuminate\Contracts\Auth\MustVerifyEmail&User $user */
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }
}
