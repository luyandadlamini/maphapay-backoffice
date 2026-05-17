<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Pricing;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Pricing\Models\PricingScenario;
use App\Jobs\Pricing\RunPricingScenarioJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class RunPricingScenarioJobTest extends ControllerTestCase
{
    #[Test]
    public function job_executes_simulator_and_persists_results(): void
    {
        $productId = (int) DB::table('pricing_products')->insertGetId([
            'code'                   => 'local_transfer_job',
            'name'                   => 'Local Transfer (job)',
            'category'               => 'local_transfer',
            'default_currency'       => 'SZL',
            'elasticity_bps_per_pct' => 0,
            'active'                 => true,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $ruleId = (int) DB::table('pricing_rules')->insertGetId([
            'product_id' => $productId,
            'name'       => 'rule-job',
            'formula'    => 'fixed',
            'config'     => json_encode(['fixed_minor' => 100]),
            'priority'   => 10,
            'status'     => 'active',
            'version'    => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scenarioId = (int) DB::table('pricing_scenarios')->insertGetId([
            'name'       => 'job-scenario',
            'status'     => 'draft',
            'mode'       => 'deterministic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pricing_scenario_rules')->insert([
            'scenario_id'     => $scenarioId,
            'pricing_rule_id' => $ruleId,
            'config_override' => json_encode(['fixed_minor' => 300]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $now = Carbon::parse('2026-05-12 10:00:00');
        for ($i = 0; $i < 10; $i++) {
            TransactionProjection::factory()->create([
                'asset_code'    => 'SZL',
                'amount'        => 1_000,
                'type'          => 'transfer',
                'subtype'       => 'send_money',
                'source_domain' => 'send_money',
                'status'        => 'completed',
                'account_uuid'  => (string) Str::uuid(),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        (new RunPricingScenarioJob(
            scenarioId: $scenarioId,
            fromDate: '2026-05-01 00:00:00',
            toDate: '2026-05-31 23:59:59',
        ))->handle(app(\App\Domain\Pricing\Services\ScenarioSimulator::class));

        /** @var PricingScenario $scenario */
        $scenario = PricingScenario::findOrFail($scenarioId);
        $this->assertNotNull($scenario->last_run_at);
        $this->assertSame(300 * 10, $scenario->last_run_result['total_gross_revenue_minor']);
    }

    #[Test]
    public function tries_is_bounded_to_two(): void
    {
        $job = new RunPricingScenarioJob(
            scenarioId: 1,
            fromDate: '2026-05-01',
            toDate: '2026-05-31',
        );

        $this->assertSame(2, $job->tries);
    }
}
