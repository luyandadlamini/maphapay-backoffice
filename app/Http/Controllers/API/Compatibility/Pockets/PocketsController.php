<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Pockets;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/pockets
 *
 * Returns savings pockets for the authenticated user.
 */
class PocketsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => [],
        ]);
    }
}
