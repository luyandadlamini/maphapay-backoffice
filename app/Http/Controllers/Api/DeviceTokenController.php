<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DeviceTokenController extends Controller
{
    #[OA\Post(
        path: '/api/device-tokens',
        summary: 'Register device token for push notifications',
        description: 'Stores or updates a device token for push notification delivery',
        operationId: 'storeDeviceToken',
        tags: ['Device'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token', 'platform'], properties: [
            new OA\Property(property: 'token', type: 'string', example: 'fcm_or_apns_token_here'),
            new OA\Property(property: 'platform', type: 'string', enum: ['ios', 'android', 'web'], example: 'ios'),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'Device token stored',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string'),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthenticated',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated'),
        ])
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'    => 'required|string',
            'platform' => 'required|string|in:ios,android,web',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $prefs = is_array($user->mobile_preferences) ? $user->mobile_preferences : [];
        $prefs['device_token'] = $validated['token'];
        $prefs['device_platform'] = $validated['platform'];
        $user->mobile_preferences = $prefs;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Device token stored',
        ]);
    }
}
