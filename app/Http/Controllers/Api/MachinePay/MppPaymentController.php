<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MachinePay;

use App\Domain\MachinePay\Models\MppPayment;
use App\Domain\MachinePay\Models\MppSpendingLimit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MppPaymentController extends Controller
{
    /**
     * List payment history.
     */
    public function index(Request $request): JsonResponse
    {
        $payments = MppPayment::query()
            ->when($request->filled('rail'), fn ($q) => $q->where('rail', $request->input('rail')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $payments,
        ]);
    }

    /**
     * Payment statistics.
     */
    public function stats(): JsonResponse
    {
        $total = MppPayment::count();
        $settled = MppPayment::where('status', 'settled')->count();
        $failed = MppPayment::where('status', 'failed')->count();
        $totalAmount = MppPayment::where('status', 'settled')->sum('amount_cents');

        $byRail = MppPayment::where('status', 'settled')
            ->selectRaw('rail, COUNT(*) as count, SUM(amount_cents) as total_cents')
            ->groupBy('rail')
            ->get()
            ->keyBy('rail')
            ->toArray();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_payments'      => $total,
                'settled'             => $settled,
                'failed'              => $failed,
                'total_settled_cents' => (int) $totalAmount,
                'by_rail'             => $byRail,
            ],
        ]);
    }

    /**
     * List agent spending limits.
     */
    public function spendingLimits(): JsonResponse
    {
        $limits = MppSpendingLimit::orderBy('agent_id')->get();

        return response()->json([
            'success' => true,
            'data'    => $limits,
        ]);
    }

    /**
     * Set or update a spending limit.
     */
    public function setSpendingLimit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id'     => ['required', 'string', 'max:255'],
            'daily_limit'  => ['required', 'integer', 'min:0'],
            'per_tx_limit' => ['required', 'integer', 'min:0'],
            'auto_pay'     => ['sometimes', 'boolean'],
        ]);

        $limit = MppSpendingLimit::updateOrCreate(
            ['agent_id' => $validated['agent_id']],
            $validated,
        );

        return response()->json([
            'success' => true,
            'data'    => $limit,
        ], 201);
    }

    /**
     * Delete a spending limit.
     */
    public function deleteSpendingLimit(string $agentId): JsonResponse
    {
        MppSpendingLimit::where('agent_id', $agentId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Spending limit deleted.',
        ]);
    }
}
