<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\VirtualCard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VirtualCardTransactionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'remark' => 'Transactions retrieved successfully',
            'status' => 'success',
            'message' => ['Virtual card transactions retrieved successfully'],
            'data' => [
                'transactions' => [],
            ],
        ]);
    }
}