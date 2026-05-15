<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mock\Wallets\StandardUnayo;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'access_token' => 'mock-unayo-token-' . bin2hex(random_bytes(8)),
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ]);
    }
}
