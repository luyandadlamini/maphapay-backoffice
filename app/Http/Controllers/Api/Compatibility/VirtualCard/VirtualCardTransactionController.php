<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VirtualCard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VirtualCardTransactionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => 'Transactions retrieved successfully',
            'data'    => [
                'transactions' => [],
            ],
        ]);
    }
}
