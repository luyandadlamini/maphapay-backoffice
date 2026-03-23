<?php

declare(strict_types=1);

namespace App\Domain\VirtualsAgent\Services;

use App\Domain\VirtualsAgent\DataObjects\AgdpMetrics;
use App\Domain\VirtualsAgent\Enums\AgentStatus;
use App\Domain\VirtualsAgent\Models\VirtualsAgentProfile;
use App\Domain\VisaCli\Models\VisaCliPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agent GDP (aGDP) reporting — aggregate economic metrics for Virtuals agents.
 *
 * Tracks total payment volume, transaction counts, and agent activity
 * to measure the economic output of the Virtuals agent ecosystem.
 */
class AgdpReportingService
{
    /**
     * Get aggregate aGDP metrics for the specified reporting period.
     */
    public function getMetrics(string $period = '24h'): AgdpMetrics
    {
        $since = $this->resolvePeriodStart($period);

        // Get all Virtuals agent IDs for cross-referencing payments
        $agentIds = VirtualsAgentProfile::pluck('virtuals_agent_id')->all();

        // Sum payment amounts and count transactions for Virtuals agents
        $paymentStats = VisaCliPayment::whereIn('agent_id', $agentIds)
            ->where('created_at', '>=', $since)
            ->select([
                DB::raw('COALESCE(SUM(amount_cents), 0) as total_cents'),
                DB::raw('COUNT(*) as total_transactions'),
            ])
            ->first();

        $totalCents = (int) ($paymentStats->total_cents ?? 0);
        $totalTransactions = (int) ($paymentStats->total_transactions ?? 0);

        $activeAgents = VirtualsAgentProfile::where('status', AgentStatus::ACTIVE)->count();
        $totalAgents = VirtualsAgentProfile::count();

        return new AgdpMetrics(
            totalPaymentsCents: $totalCents,
            totalTransactions: $totalTransactions,
            activeAgents: $activeAgents,
            totalAgents: $totalAgents,
            period: $period,
            calculatedAt: now()->toIso8601String(),
        );
    }

    /**
     * Get per-agent payment contribution totals.
     *
     * @return array<string, mixed>
     */
    public function getAgentContribution(string $virtualsAgentId): array
    {
        $profile = VirtualsAgentProfile::where('virtuals_agent_id', $virtualsAgentId)->first();

        if ($profile === null) {
            return [
                'agent_id'           => $virtualsAgentId,
                'total_payments'     => 0,
                'total_amount_cents' => 0,
                'first_payment_at'   => null,
                'last_payment_at'    => null,
            ];
        }

        $stats = VisaCliPayment::where('agent_id', $virtualsAgentId)
            ->select([
                DB::raw('COUNT(*) as total_payments'),
                DB::raw('COALESCE(SUM(amount_cents), 0) as total_amount_cents'),
                DB::raw('MIN(created_at) as first_payment_at'),
                DB::raw('MAX(created_at) as last_payment_at'),
            ])
            ->first();

        return [
            'agent_id'           => $virtualsAgentId,
            'agent_name'         => $profile->agent_name,
            'status'             => $profile->status->value,
            'total_payments'     => (int) ($stats->total_payments ?? 0),
            'total_amount_cents' => (int) ($stats->total_amount_cents ?? 0),
            'first_payment_at'   => $stats->first_payment_at,
            'last_payment_at'    => $stats->last_payment_at,
        ];
    }

    /**
     * Resolve a period string to a Carbon start timestamp.
     */
    private function resolvePeriodStart(string $period): Carbon
    {
        return match ($period) {
            '1h'    => now()->subHour(),
            '24h'   => now()->subDay(),
            '7d'    => now()->subWeek(),
            '30d'   => now()->subMonth(),
            default => now()->subDay(),
        };
    }
}
