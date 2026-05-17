<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Pricing;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Pricing\Models\PricingScenario;
use App\Domain\Pricing\Services\ScenarioSimulator;
use App\Domain\Pricing\ValueObjects\ScenarioMetrics;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class ScenarioSimulatorTest extends ControllerTestCase
{
    private int $productLocalId;

    private int $ruleLocalId;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = Carbon::parse('2026-05-01 00:00:00');
        $this->to = Carbon::parse('2026-05-31 23:59:59');

        $this->productLocalId = (int) DB::table('pricing_products')->insertGetId([
            'code'                   => 'local_transfer_sim',
            'name'                   => 'Local Transfer (sim)',
            'category'               => 'local_transfer',
            'default_currency'       => 'SZL',
            'elasticity_bps_per_pct' => 0,
            'active'                 => true,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $this->ruleLocalId = (int) DB::table('pricing_rules')->insertGetId([
            'product_id' => $this->productLocalId,
            'segment_id' => null,
            'name'       => 'baseline-local',
            'formula'    => 'fixed',
            'config'     => json_encode(['fixed_minor' => 100]),
            'priority'   => 10,
            'status'     => 'active',
            'version'    => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function deterministic_run_doubles_revenue_when_override_doubles_fixed_fee(): void
    {
        $this->seedTransactions(count: 50, sourceDomain: 'send_money', amount: 1_000);
        $this->seedTransactions(count: 50, sourceDomain: 'request_money_received', amount: 1_000);

        $scenario = $this->seedScenarioWithOverride(['fixed_minor' => 200]);

        $metrics = app(ScenarioSimulator::class)->run($scenario, $this->from, $this->to);

        $this->assertInstanceOf(ScenarioMetrics::class, $metrics);
        $this->assertArrayHasKey('local_transfer', $metrics->byCategory);
        $this->assertSame(100, $metrics->byCategory['local_transfer']['fee_count']);
        $this->assertSame(200 * 100, $metrics->byCategory['local_transfer']['gross_revenue_minor']);
        $this->assertSame(200 * 100, $metrics->totalGrossRevenueMinor);
    }

    #[Test]
    public function deterministic_run_without_override_returns_baseline_revenue(): void
    {
        $this->seedTransactions(count: 30, sourceDomain: 'send_money', amount: 1_000);

        // Scenario exists but has no rule overrides.
        $scenarioId = (int) DB::table('pricing_scenarios')->insertGetId([
            'name'       => 'no-override',
            'status'     => 'draft',
            'mode'       => 'deterministic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        /** @var PricingScenario $scenario */
        $scenario = PricingScenario::findOrFail($scenarioId);

        $metrics = app(ScenarioSimulator::class)->run($scenario, $this->from, $this->to);

        $this->assertSame(100 * 30, $metrics->totalGrossRevenueMinor);
        $this->assertSame(30, $metrics->byCategory['local_transfer']['fee_count']);
    }

    #[Test]
    public function behavioural_with_zero_elasticity_matches_deterministic(): void
    {
        $this->seedTransactions(count: 20, sourceDomain: 'send_money', amount: 1_000);

        $scenario = $this->seedScenarioWithOverride(['fixed_minor' => 250]);

        $deterministic = app(ScenarioSimulator::class)->run($scenario, $this->from, $this->to, behavioural: false);
        $behavioural = app(ScenarioSimulator::class)->run($scenario, $this->from, $this->to, behavioural: true);

        $this->assertSame(
            $deterministic->totalGrossRevenueMinor,
            $behavioural->totalGrossRevenueMinor,
            'With elasticity_bps_per_pct=0, behavioural mode must equal deterministic.',
        );
        $this->assertSame(
            $deterministic->byCategory['local_transfer']['fee_count'],
            $behavioural->byCategory['local_transfer']['fee_count'],
        );
    }

    #[Test]
    public function unmapped_source_domains_are_skipped(): void
    {
        $this->seedTransactions(count: 10, sourceDomain: 'send_money', amount: 1_000);
        $this->seedTransactions(count: 10, sourceDomain: 'random_unknown_thing', amount: 1_000);

        $scenario = $this->seedScenarioWithOverride(['fixed_minor' => 100]);

        $metrics = app(ScenarioSimulator::class)->run($scenario, $this->from, $this->to);

        $this->assertSame(10, $metrics->byCategory['local_transfer']['fee_count']);
        $this->assertSame(100 * 10, $metrics->totalGrossRevenueMinor);
    }

    #[Test]
    public function run_persists_last_run_result_and_timestamp(): void
    {
        $this->seedTransactions(count: 5, sourceDomain: 'send_money', amount: 1_000);

        $scenario = $this->seedScenarioWithOverride(['fixed_minor' => 100]);

        app(ScenarioSimulator::class)->run($scenario, $this->from, $this->to);

        $scenario->refresh();
        $this->assertNotNull($scenario->last_run_at);
        $this->assertIsArray($scenario->last_run_result);
        $this->assertSame(500, $scenario->last_run_result['total_gross_revenue_minor']);
    }

    // ---------- helpers ----------

    /** @param array<string, mixed> $override */
    private function seedScenarioWithOverride(array $override): PricingScenario
    {
        $scenarioId = (int) DB::table('pricing_scenarios')->insertGetId([
            'name'       => 'sim-' . uniqid(),
            'status'     => 'draft',
            'mode'       => 'deterministic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pricing_scenario_rules')->insert([
            'scenario_id'     => $scenarioId,
            'pricing_rule_id' => $this->ruleLocalId,
            'config_override' => json_encode($override),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        /** @var PricingScenario $scenario */
        $scenario = PricingScenario::findOrFail($scenarioId);

        return $scenario;
    }

    private function seedTransactions(int $count, string $sourceDomain, int $amount): void
    {
        $now = Carbon::parse('2026-05-10 12:00:00');

        for ($i = 0; $i < $count; $i++) {
            TransactionProjection::factory()->create([
                'asset_code'    => 'SZL',
                'amount'        => $amount,
                'type'          => 'transfer',
                'subtype'       => $sourceDomain,
                'source_domain' => $sourceDomain,
                'status'        => 'completed',
                'account_uuid'  => (string) Str::uuid(),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }
}
