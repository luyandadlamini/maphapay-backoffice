<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Analytics\Services;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Analytics\Services\WalletRevenueActivityMetrics;
use App\Domain\Analytics\WalletRevenueStream;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class WalletRevenueActivityMetricsTest extends TestCase
{
    public function test_cache_key_is_stable_for_same_normalized_window(): void
    {
        $svc = new WalletRevenueActivityMetrics();

        $start = Carbon::parse('2026-01-01')->startOfDay();
        $end = Carbon::parse('2026-01-10')->endOfDay();

        $this->assertSame(
            $svc->cacheKeyForPeriod($start, $end),
            $svc->cacheKeyForPeriod($start, $end)
        );
    }

    public function test_window_is_capped_to_config_max_days(): void
    {
        config(['maphapay.revenue_activity_max_window_days' => 10]);

        $svc = new WalletRevenueActivityMetrics();

        $start = Carbon::parse('2026-01-01')->startOfDay();
        $end = Carbon::parse('2026-03-01')->endOfDay();

        [$ns, $ne] = $svc->normalizeAndCapWindow($start, $end);

        // Ten inclusive calendar days ending 2026-03-01 → start 2026-02-20 (see service: subDays(max-1)).
        $this->assertSame('2026-02-20', $ns->toDateString());
        $this->assertSame('2026-03-01', $ne->toDateString());
    }

    public function test_p2p_and_cashout_map_from_projections_others_pending(): void
    {
        config(['maphapay.revenue_activity_metrics_ttl_seconds' => 60]);

        $start = Carbon::parse('2030-05-01')->startOfDay();
        $end = Carbon::parse('2030-05-07')->endOfDay();

        TransactionProjection::factory()->transfer()->create([
            'created_at' => Carbon::parse('2030-05-02 12:00:00'),
            'amount'     => 500,
            'asset_code' => 'ZAR',
            'status'     => 'completed',
        ]);
        TransactionProjection::factory()->withdrawal()->create([
            'created_at' => Carbon::parse('2030-05-03 12:00:00'),
            'amount'     => 200,
            'asset_code' => 'ZAR',
            'status'     => 'completed',
        ]);
        TransactionProjection::factory()->deposit()->create([
            'created_at' => Carbon::parse('2030-05-04 12:00:00'),
            'amount'     => 999,
            'asset_code' => 'ZAR',
            'status'     => 'completed',
        ]);

        Cache::flush();

        $svc = new WalletRevenueActivityMetrics();
        $result = $svc->forPeriod($start, $end);

        $p2p = $result->streamMetrics[WalletRevenueStream::P2pSend->value];
        $this->assertTrue($p2p->isMapped());
        $this->assertSame(1, $p2p->transactionCount);
        $this->assertSame(500, $p2p->volumesByAsset['ZAR'] ?? 0);

        $cash = $result->streamMetrics[WalletRevenueStream::Cashout->value];
        $this->assertTrue($cash->isMapped());
        $this->assertSame(1, $cash->transactionCount);

        $this->assertFalse($result->streamMetrics[WalletRevenueStream::MerchantPay->value]->isMapped());

        $this->assertSame(2, $result->overview->transactionCount);
        $this->assertSame(700, $result->overview->volumesByAsset['ZAR'] ?? 0);
    }

    public function test_stub_reader_returns_fixed_snapshot_outside_production(): void
    {
        config(['maphapay.revenue_activity_stub_reader' => true]);
        Cache::flush();

        $svc = new WalletRevenueActivityMetrics();
        $start = Carbon::parse('2030-06-01')->startOfDay();
        $end = Carbon::parse('2030-06-07')->endOfDay();

        $result = $svc->forPeriod($start, $end);

        $this->assertSame(3, $result->overview->transactionCount);
        $this->assertSame(250_000, $result->overview->volumesByAsset['ZAR'] ?? 0);
        $this->assertSame(2, $result->streamMetrics[WalletRevenueStream::P2pSend->value]->transactionCount);
    }
}
