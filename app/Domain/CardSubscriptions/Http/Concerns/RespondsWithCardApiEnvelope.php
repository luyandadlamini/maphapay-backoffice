<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Concerns;

use Illuminate\Http\JsonResponse;

trait RespondsWithCardApiEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function cardSuccess(string $remark, array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'remark' => $remark,
            'data'   => $data,
        ], $status);
    }
}
