<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mock\Wallets\MtnMomo;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class TokenController extends Controller
{
    public function collection(Request $request): JsonResponse
    {
        return $this->token($request, 'collection');
    }

    public function disbursement(Request $request): JsonResponse
    {
        return $this->token($request, 'disbursement');
    }

    private function token(Request $request, string $product): JsonResponse
    {
        if (! str_starts_with((string) $request->header('Authorization'), 'Basic ')) {
            return response()->json(['message' => 'Missing basic auth.'], 401);
        }

        $token = 'mock-' . $product . '-' . Str::uuid();
        Redis::setex("mock:wallet:mtn:token:{$product}", 3500, $token);

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'access_token',
            'expires_in'   => 3600,
        ]);
    }
}
