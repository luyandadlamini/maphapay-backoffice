<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mock\Wallets\Emali;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Accept basic-auth or client_credentials body; we don't validate in mock.
        return response()->json([
            'access_token' => 'mock-emali-access-token-' . bin2hex(random_bytes(8)),
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ]);
    }
}
