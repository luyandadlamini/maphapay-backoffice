<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class UserProfileController extends Controller
{
    /**
     * Upload or replace user avatar.
     */
    #[OA\Post(
        path: '/api/v1/users/avatar',
        operationId: 'uploadAvatar',
        summary: 'Upload user avatar',
        tags: ['User'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(required: ['avatar'], properties: [
            new OA\Property(property: 'avatar', type: 'string', format: 'binary', description: 'Image file (jpg, png, webp, max 2MB)'),
            ])
        ))
    )]
    #[OA\Response(response: 200, description: 'Avatar uploaded')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Delete old avatar if exists
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('avatar');
        $path = $file->store('avatars', 'public');

        if ($path === false) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'UPLOAD_FAILED', 'message' => 'Failed to store avatar.'],
            ], 500);
        }

        $user->update(['avatar' => $path]);

        return response()->json([
            'success' => true,
            'data'    => [
                'avatar_url' => Storage::disk('public')->url($path),
            ],
        ]);
    }

    /**
     * Remove user avatar.
     */
    #[OA\Delete(
        path: '/api/v1/users/avatar',
        operationId: 'deleteAvatar',
        summary: 'Remove user avatar',
        tags: ['User'],
        security: [['sanctum' => []]]
    )]
    #[OA\Response(response: 200, description: 'Avatar removed')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->update(['avatar' => null]);

        return response()->json([
            'success' => true,
            'data'    => ['message' => 'Avatar removed.'],
        ]);
    }

    /**
     * Set or toggle Transaction PIN requirement (dummy toggle since actual pin hash is used to determine)
     */
    #[OA\Post(
        path: '/api/v1/users/transaction-pin/toggle',
        operationId: 'toggleTransactionPin',
        summary: 'Toggle transaction PIN status',
        tags: ['User'],
        security: [['sanctum' => []]]
    )]
    #[OA\Response(response: 200, description: 'Transaction PIN requirement updated')]
    public function toggleTransactionPin(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        
        $enabled = $request->boolean('enabled');
        
        // Note: The system currently determines PIN requirement based on if `transaction_pin` is null.
        // Toggling it off means you wipe the PIN. If you want to retain it but just "disable" it, you'll need the user to reset it later.
        // A better approach in a real system is a separate boolean column. Since we only have `transaction_pin`, 
        // if they disable it, we delete it, because AuthorizedTransactionManager throws an error if they try to use it while it's null.
        // Let's implement an approach where setting to false nullifies it, and setting to true is handled by actual reset flows. 
        // For now, if disabling, we nullify the PIN.
        
        if (!$enabled) {
            $user->update(['transaction_pin' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transaction PIN settings updated.',
            'data' => [],
        ]);
    }
}
