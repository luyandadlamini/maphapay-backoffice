<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Analytics\DTO\OverviewActivityDto;
use App\Domain\Analytics\DTO\RevenueTargetAnomalyRowDto;
use App\Domain\Analytics\DTO\StreamActivityMetricsDto;
use App\Domain\Analytics\DTO\WalletRevenueActivityResult;
use App\Domain\Analytics\Models\RevenueTarget;
use App\Domain\Analytics\WalletRevenueStream;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Tenant-scoped, cached **activity** metrics from `transaction_projections` (v1).
 *
 * Mapping (conservative, documented — not fee revenue):
 * - {@see WalletRevenueStream::P2pSend}: `type = transfer`, `status = completed`.
 * - {@see WalletRevenueStream::Cashout}: `type = withdrawal`, `status = completed`.
 * All other streams: {@see StreamActivityMetricsDto::pending()}.
 *
 * Overview aggregate uses the union of those two types (same window) — no cross-asset “revenue” scalar.
 */
final class WalletRevenueActivityMetrics
{
    /**
     * v1 P2P heuristic: all completed ledger rows typed `transfer` in the projection mart.
     * Subtype refinement waits on finance sign-off.
     */
    private const P2P_TYPES = ['transfer'];

    /**
     * v1 cash-out heuristic: completed `withdrawal` rows (payout / cash-out family).
     */
    private const CASHOUT_TYPES = ['withdrawal'];

    /**
     * Overview headline aggregate: rows that back any v1-mapped stream card.
     */
    private const OVERVIEW_TYPES = ['transfer', 'withdrawal'];

    public function forPeriod(CarbonInterface $start, CarbonInterface $end): WalletRevenueActivityResult
    {
        [$start, $end] = $this->normalizeAndCapWindow($start, $end);

        $ttl = (int) config('maphapay.revenue_activity_metrics_ttl_seconds', 120);
        $key = $this->cacheKey($start, $end);

        /** @var WalletRevenueActivityResult */
        return Cache::remember(
            $key,
            max(5, $ttl),
            fn (): WalletRevenueActivityResult => $this->compute($start, $end)
        );
    }

    /**
     * Stable cache key for tests / invalidation (same inputs as {@see self::forPeriod()} after normalization).
     */
    public function cacheKeyForPeriod(CarbonInterface $start, CarbonInterface $end): string
    {
        [$start, $end] = $this->normalizeAndCapWindow($start, $end);

        return $this->cacheKey($start, $end);
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function normalizeAndCapWindow(CarbonInterface $start, CarbonInterface $end): array
    {
        $maxDays = max(1, (int) config('maphapay.revenue_activity_max_window_days', 93));

        $s = $start->copy()->startOfDay();
        $e = $end->copy()->endOfDay();

        if ($s->greaterThan($e)) {
            [$s, $e] = [$e->copy()->startOfDay(), $s->copy()->endOfDay()];
        }

        if ($s->diffInDays($e) >= $maxDays) {
            $s = $e->copy()->subDays($maxDays - 1)->startOfDay();
        }

        return [$s, $e];
    }

    private function cacheKey(CarbonInterface $start, CarbonInterface $end): string
    {
        $stub = $this->activityStubActive() ? ':stub' : '';

        return 'wallet_revenue_activity:v1:' . $this->tenantCacheSuffix() . ':' . $start->toDateString() . ':' . $end->toDateString() . $stub;
    }

    private function activityStubActive(): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        return (bool) config('maphapay.revenue_activity_stub_reader', false);
    }

    private function tenantCacheSuffix(): string
    {
        $suffix = 'landlord';

        try {
            if (function_exists('tenancy') && tenancy()->initialized) {
                $suffix = (string) tenant('id');
            }
        } catch (Throwable) {
            // Landlord / unknown: shared key.
        }

        return $suffix;
    }

    private function compute(CarbonInterface $start, CarbonInterface $end): WalletRevenueActivityResult
    {
        if ($this->activityStubActive()) {
            return $this->stubWalletRevenueActivityResult($start, $end);
        }

        if (! Schema::hasTable('transaction_projections')) {
            return $this->emptyResult($start, $end, includeAnomalies: true);
        }

        $overview = $this->aggregateOverview($start, $end);
        $streamMetrics = [];

        foreach (WalletRevenueStream::cases() as $stream) {
            $streamMetrics[$stream->value] = match ($stream) {
                WalletRevenueStream::P2pSend => $this->metricsForTypes(
                    self::P2P_TYPES,
                    $start,
                    $end,
                    __('Counts completed `transfer` rows only (v1). Subtype splits pending finance.')
                ),
                WalletRevenueStream::Cashout => $this->metricsForTypes(
                    self::CASHOUT_TYPES,
                    $start,
                    $end,
                    __('Counts completed `withdrawal` rows only (v1). Partner settlement not joined.')
                ),
                default => StreamActivityMetricsDto::pending(),
            };
        }

        $anomalies = $this->loadAnomalousTargets();

        return new WalletRevenueActivityResult(
            $start,
            $end,
            $overview,
            $streamMetrics,
            $anomalies,
        );
    }

    private function emptyResult(CarbonInterface $start, CarbonInterface $end, bool $includeAnomalies = false): WalletRevenueActivityResult
    {
        $pending = StreamActivityMetricsDto::pending();
        $streamMetrics = [];

        foreach (WalletRevenueStream::cases() as $stream) {
            $streamMetrics[$stream->value] = $pending;
        }

        $anomalies = $includeAnomalies ? $this->loadAnomalousTargets() : [];

        return new WalletRevenueActivityResult(
            $start,
            $end,
            new OverviewActivityDto(0, []),
            $streamMetrics,
            $anomalies,
        );
    }

    /**
     * @param  list<string>  $types
     */
    private function metricsForTypes(array $types, CarbonInterface $start, CarbonInterface $end, string $mappingNote): StreamActivityMetricsDto
    {
        $base = TransactionProjection::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', $types);

        $count = (int) (clone $base)->count();

        if ($count === 0) {
            return StreamActivityMetricsDto::mapped(0, [], null, $mappingNote);
        }

        /** @var array<string, int> $volumes */
        $volumes = [];
        foreach (
            (clone $base)
                ->selectRaw('asset_code, SUM(amount) as total_minor')
                ->groupBy('asset_code')
                ->get() as $row
        ) {
            $volumes[(string) $row->asset_code] = (int) $row->total_minor;
        }

        $lastAt = (clone $base)->max('created_at');
        $lastIso = $lastAt !== null ? (string) $lastAt : null;

        return StreamActivityMetricsDto::mapped($count, $volumes, $lastIso, $mappingNote);
    }

    private function aggregateOverview(CarbonInterface $start, CarbonInterface $end): OverviewActivityDto
    {
        $base = TransactionProjection::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', self::OVERVIEW_TYPES);

        $count = (int) (clone $base)->count();

        if ($count === 0) {
            return new OverviewActivityDto(0, []);
        }

        /** @var array<string, int> $volumes */
        $volumes = [];
        foreach (
            (clone $base)
                ->selectRaw('asset_code, SUM(amount) as total_minor')
                ->groupBy('asset_code')
                ->get() as $row
        ) {
            $volumes[(string) $row->asset_code] = (int) $row->total_minor;
        }

        $lastAt = (clone $base)->max('created_at');

        return new OverviewActivityDto(
            $count,
            $volumes,
            $lastAt !== null ? (string) $lastAt : null,
        );
    }

    /**
     * @return list<RevenueTargetAnomalyRowDto>
     */
    private function loadAnomalousTargets(): array
    {
        if (! Schema::hasTable('revenue_targets')) {
            return [];
        }

        /** @var list<RevenueTargetAnomalyRowDto> */
        return array_values(
            RevenueTarget::query()
                ->where('amount', '<=', 0)
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get()
                ->map(
                    static fn (RevenueTarget $t): RevenueTargetAnomalyRowDto => new RevenueTargetAnomalyRowDto(
                        (string) $t->getKey(),
                        (string) $t->period_month,
                        (string) $t->stream_code,
                        (string) $t->amount,
                        (string) $t->currency,
                    )
                )
                ->all()
        );
    }

    /**
     * Fixed snapshot for local / QA UI wiring (not ledger-backed).
     */
    private function stubWalletRevenueActivityResult(CarbonInterface $start, CarbonInterface $end): WalletRevenueActivityResult
    {
        $stubNote = __('[STUB] Local reader only — not from transaction_projections.');

        $p2pNote = __('Counts completed `transfer` rows only (v1). Subtype splits pending finance.') . ' ' . $stubNote;
        $cashNote = __('Counts completed `withdrawal` rows only (v1). Partner settlement not joined.') . ' ' . $stubNote;

        $lastIso = $end->copy()->endOfDay()->toIso8601String();

        $streamMetrics = [];
        foreach (WalletRevenueStream::cases() as $stream) {
            $streamMetrics[$stream->value] = match ($stream) {
                WalletRevenueStream::P2pSend => StreamActivityMetricsDto::mapped(
                    2,
                    ['ZAR' => 200_000],
                    $lastIso,
                    $p2pNote,
                ),
                WalletRevenueStream::Cashout => StreamActivityMetricsDto::mapped(
                    1,
                    ['ZAR' => 50_000],
                    $lastIso,
                    $cashNote,
                ),
                default => StreamActivityMetricsDto::pending(),
            };
        }

        $anomalies = Schema::hasTable('revenue_targets') ? $this->loadAnomalousTargets() : [];

        return new WalletRevenueActivityResult(
            $start,
            $end,
            new OverviewActivityDto(3, ['ZAR' => 250_000], $lastIso),
            $streamMetrics,
            $anomalies,
        );
    }
}
