<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing\Services;

use App\Domain\Pricing\Services\PricingResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PricingResolverTest extends TestCase
{
    private PricingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PricingResolver();
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    public function test_fixed_formula_returns_configured_fixed_minor(): void
    {
        $productId = $this->insertProduct(code: 'p_fixed', category: 'local_transfer');
        $this->insertRule(
            productId: $productId,
            formula: 'fixed',
            config: ['fixed_minor' => 250],
        );

        $breakdown = $this->resolver->resolve('local_transfer', 100_000, 'SZL');

        $this->assertNotNull($breakdown);
        $this->assertSame(250, $breakdown->totalMinor());
    }

    public function test_percentage_formula_applies_bps_to_amount(): void
    {
        $productId = $this->insertProduct(code: 'p_pct', category: 'wallet_to_wallet');
        $this->insertRule(
            productId: $productId,
            formula: 'percentage',
            config: ['bps' => 100], // 1%
        );

        $breakdown = $this->resolver->resolve('wallet_to_wallet', 100_000, 'SZL');

        $this->assertNotNull($breakdown);
        $this->assertSame(1_000, $breakdown->totalMinor());
    }

    public function test_hybrid_formula_combines_fixed_and_percentage(): void
    {
        $productId = $this->insertProduct(code: 'p_hyb', category: 'bank_transfer');
        $this->insertRule(
            productId: $productId,
            formula: 'hybrid',
            config: ['fixed_minor' => 500, 'bps' => 50], // 5 + 0.5%
        );

        $breakdown = $this->resolver->resolve('bank_transfer', 100_000, 'SZL');

        $this->assertNotNull($breakdown);
        // 500 fixed + 0.5% of 100_000 = 500
        $this->assertSame(1_000, $breakdown->totalMinor());
    }

    public function test_tiered_formula_waterfall_with_free_low_band(): void
    {
        $productId = $this->insertProduct(code: 'p_tier', category: 'cash_out');
        $this->insertRule(
            productId: $productId,
            formula: 'tiered',
            config: [
                'bands' => [
                    ['threshold_minor' => 0,           'bps' => 0,   'fixed_minor' => 0],
                    ['threshold_minor' => 1_000_000,   'bps' => 50,  'fixed_minor' => 0],  // 0.5% above E10k
                    ['threshold_minor' => 5_000_000,   'bps' => 100, 'fixed_minor' => 0],  // 1% above E50k
                ],
            ],
        );

        // E5,000: free
        $freeBd = $this->resolver->resolve('cash_out', 500_000, 'SZL');
        $this->assertNotNull($freeBd);
        $this->assertSame(0, $freeBd->totalMinor());

        // E20,000: 0.5% of E10k slice = 5_000 minor
        $midBd = $this->resolver->resolve('cash_out', 2_000_000, 'SZL');
        $this->assertNotNull($midBd);
        $this->assertSame(5_000, $midBd->totalMinor());

        // E60,000: E40k @ 50bps + E10k @ 100bps = 20_000 + 10_000 = 30_000
        $hiBd = $this->resolver->resolve('cash_out', 6_000_000, 'SZL');
        $this->assertNotNull($hiBd);
        $this->assertSame(30_000, $hiBd->totalMinor());
    }

    public function test_rule_with_future_effective_from_is_not_selected(): void
    {
        $productId = $this->insertProduct(code: 'p_future', category: 'atm_withdrawal');
        $this->insertRule(
            productId: $productId,
            formula: 'fixed',
            config: ['fixed_minor' => 999],
            effectiveFrom: Carbon::now()->addDay(),
        );

        $this->assertNull($this->resolver->resolve('atm_withdrawal', 1_000, 'SZL'));
    }

    public function test_higher_priority_rule_wins(): void
    {
        $productId = $this->insertProduct(code: 'p_prio', category: 'merchant_payment');
        $this->insertRule(productId: $productId, formula: 'fixed', config: ['fixed_minor' => 100], priority: 1);
        $this->insertRule(productId: $productId, formula: 'fixed', config: ['fixed_minor' => 700], priority: 10);

        $breakdown = $this->resolver->resolve('merchant_payment', 50_000, 'SZL');

        $this->assertNotNull($breakdown);
        $this->assertSame(700, $breakdown->totalMinor());
    }

    public function test_segment_scoped_rule_is_skipped_for_non_member(): void
    {
        $productId = $this->insertProduct(code: 'p_seg', category: 'airtime');
        $segmentId = $this->insertSegment(code: 'vip');

        $this->insertRule(
            productId: $productId,
            formula: 'fixed',
            config: ['fixed_minor' => 1],
            segmentId: $segmentId,
            priority: 100,
        );

        // user 9999 is not a member of segmentId
        $this->assertNull($this->resolver->resolve('airtime', 1_000, 'SZL', userId: 9999));
    }

    public function test_returns_null_when_no_active_rule_matches(): void
    {
        $this->insertProduct(code: 'p_none', category: 'bill_payment');
        $this->assertNull($this->resolver->resolve('bill_payment', 1_000, 'SZL'));
    }

    public function test_cap_max_minor_clamps_result(): void
    {
        $productId = $this->insertProduct(code: 'p_cap', category: 'fx_conversion');
        $this->insertRule(
            productId: $productId,
            formula: 'percentage',
            config: ['bps' => 1_000, 'cap_max_minor' => 5_000], // 10% capped at 5_000
        );

        $breakdown = $this->resolver->resolve('fx_conversion', 1_000_000, 'SZL');

        $this->assertNotNull($breakdown);
        // 10% of 1M = 100_000 but capped at 5_000
        $this->assertSame(5_000, $breakdown->totalMinor());
    }

    public function test_discount_bps_reduces_result(): void
    {
        $productId = $this->insertProduct(code: 'p_disc', category: 'cross_border');
        $this->insertRule(
            productId: $productId,
            formula: 'percentage',
            config: ['bps' => 100, 'discount_bps' => 2_500], // 25% discount off the 1% fee
        );

        $breakdown = $this->resolver->resolve('cross_border', 100_000, 'SZL');

        $this->assertNotNull($breakdown);
        // 1% of 100_000 = 1_000; 25% discount = -250 -> 750
        $this->assertSame(750, $breakdown->totalMinor());
    }

    // ---------- helpers ----------

    /**
     * @param array<string, mixed> $config
     * @param array<int, string>|null $geoScope
     */
    private function insertRule(
        int $productId,
        string $formula,
        array $config,
        ?int $segmentId = null,
        int $priority = 0,
        ?Carbon $effectiveFrom = null,
        ?Carbon $effectiveTo = null,
        string $status = 'active',
        ?string $channel = null,
        ?array $geoScope = null,
    ): int {
        return (int) DB::table('pricing_rules')->insertGetId([
            'product_id'     => $productId,
            'segment_id'     => $segmentId,
            'name'           => 'rule-' . uniqid(),
            'formula'        => $formula,
            'config'         => json_encode($config),
            'geo_scope'      => $geoScope !== null ? json_encode($geoScope) : null,
            'channel'        => $channel,
            'priority'       => $priority,
            'status'         => $status,
            'version'        => 1,
            'effective_from' => $effectiveFrom,
            'effective_to'   => $effectiveTo,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function insertProduct(string $code, string $category): int
    {
        return (int) DB::table('pricing_products')->insertGetId([
            'code'             => $code,
            'name'             => $code,
            'category'         => $category,
            'default_currency' => 'SZL',
            'active'           => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function insertSegment(string $code): int
    {
        return (int) DB::table('customer_segments')->insertGetId([
            'code'       => $code,
            'name'       => $code,
            'source'     => 'static',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
