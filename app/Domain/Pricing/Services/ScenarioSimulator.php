<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Pricing\Models\PricingScenario;
use App\Domain\Pricing\ValueObjects\ScenarioMetrics;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Runs a pricing scenario over a slice of historical transactions and
 * produces a {@see ScenarioMetrics} aggregate.
 *
 * Modes:
 *   - deterministic (default): sums the *scenario* fee for every matching
 *     transaction. Useful for "what would we have earned?".
 *   - behavioural: also computes the *current* fee per transaction so that
 *     a category-level price-change ratio can be fed into the
 *     {@see ElasticityModel} to adjust volume.
 *
 * Transactions are mapped to a pricing category via $sourceDomainMap. Rows
 * whose source_domain is unknown are silently skipped — the simulator is
 * tolerant by design so a new feature emitting unfamiliar source_domains
 * doesn't break revenue forecasting.
 */
class ScenarioSimulator
{
    /** Static map from TransactionProjection.source_domain to pricing category. */
    public const SOURCE_DOMAIN_MAP = [
        'send_money'             => 'local_transfer',
        'request_money_received' => 'local_transfer',
        'card_product'           => 'virtual_card_transaction',
    ];

    public function __construct(private readonly PricingResolver $resolver)
    {
    }

    public function run(
        PricingScenario $scenario,
        Carbon $from,
        Carbon $to,
        bool $behavioural = false,
    ): ScenarioMetrics {
        $overrides = $this->loadOverrides($scenario);
        $productMeta = $this->loadProductMeta();

        /** @var array<string, array{product_code: string, scenario_revenue: int, current_revenue: int, count: int, users: array<string, bool>}> $byCategory */
        $byCategory = [];
        $allUsers = [];

        TransactionProjection::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$byCategory, &$allUsers, $overrides, $productMeta, $behavioural): void {
                foreach ($rows as $row) {
                    /** @var TransactionProjection $row */
                    $domain = (string) ($row->source_domain ?? '');
                    $category = self::SOURCE_DOMAIN_MAP[$domain] ?? null;
                    if ($category === null) {
                        continue;
                    }

                    $amount = (int) $row->amount;
                    $currency = (string) ($row->asset_code ?? '');

                    $scenarioFee = $this->resolver->resolveWithOverrides(
                        category: $category,
                        amountMinor: $amount,
                        currency: $currency,
                        overrides: $overrides,
                    );

                    if ($scenarioFee === null) {
                        continue;
                    }

                    $scenarioTotal = $scenarioFee->totalMinor();

                    $currentTotal = 0;
                    if ($behavioural) {
                        $currentFee = $this->resolver->resolve(
                            category: $category,
                            amountMinor: $amount,
                            currency: $currency,
                        );
                        $currentTotal = $currentFee?->totalMinor() ?? 0;
                    }

                    if (! isset($byCategory[$category])) {
                        $byCategory[$category] = [
                            'product_code'     => $productMeta[$category]['code'] ?? $category,
                            'scenario_revenue' => 0,
                            'current_revenue'  => 0,
                            'count'            => 0,
                            'users'            => [],
                        ];
                    }

                    $byCategory[$category]['scenario_revenue'] += $scenarioTotal;
                    $byCategory[$category]['current_revenue'] += $currentTotal;
                    $byCategory[$category]['count']++;

                    $userKey = (string) ($row->account_uuid ?? '');
                    if ($userKey !== '') {
                        $byCategory[$category]['users'][$userKey] = true;
                        $allUsers[$userKey] = true;
                    }
                }
            });

        $metrics = $this->aggregate($byCategory, $allUsers, $productMeta, $behavioural);

        $scenario->forceFill([
            'last_run_result' => $metrics->toArray(),
            'last_run_at'     => Carbon::now(),
        ])->save();

        return $metrics;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadOverrides(PricingScenario $scenario): array
    {
        $rows = DB::table('pricing_scenario_rules')
            ->where('scenario_id', $scenario->getKey())
            ->whereNotNull('pricing_rule_id')
            ->get(['pricing_rule_id', 'config_override']);

        $overrides = [];
        foreach ($rows as $row) {
            $config = json_decode((string) ($row->config_override ?? '{}'), true);
            if (! is_array($config)) {
                continue;
            }
            $overrides[(int) $row->pricing_rule_id] = $config;
        }

        return $overrides;
    }

    /**
     * @return array<string, array{code: string, elasticity_bps: int}>
     */
    private function loadProductMeta(): array
    {
        $rows = DB::table('pricing_products')->get(['code', 'category', 'elasticity_bps_per_pct']);

        $meta = [];
        foreach ($rows as $row) {
            $category = (string) $row->category;
            // First-write wins; production callers can scope by active() if multiple products share a category.
            if (! isset($meta[$category])) {
                $meta[$category] = [
                    'code'           => (string) $row->code,
                    'elasticity_bps' => (int) ($row->elasticity_bps_per_pct ?? 0),
                ];
            }
        }

        return $meta;
    }

    /**
     * @param array<string, array{product_code: string, scenario_revenue: int, current_revenue: int, count: int, users: array<string, bool>}> $byCategory
     * @param array<string, bool> $allUsers
     * @param array<string, array{code: string, elasticity_bps: int}> $productMeta
     */
    private function aggregate(array $byCategory, array $allUsers, array $productMeta, bool $behavioural): ScenarioMetrics
    {
        $summary = [];
        $totalRevenue = 0;

        foreach ($byCategory as $category => $bucket) {
            $count = $bucket['count'];
            $scenarioRevenue = $bucket['scenario_revenue'];

            if ($behavioural && $count > 0) {
                $scenarioAvg = (int) round($scenarioRevenue / $count);
                $currentAvg = (int) round($bucket['current_revenue'] / $count);
                $feePctChange = ($scenarioAvg - $currentAvg) / max(1, $currentAvg);
                $elasticityBps = $productMeta[$category]['elasticity_bps'] ?? 0;
                $adjustedCount = ElasticityModel::apply($count, $feePctChange, $elasticityBps);
                $scenarioRevenue = $scenarioAvg * $adjustedCount;
                $count = $adjustedCount;
            }

            $avg = $count > 0 ? (int) round($scenarioRevenue / $count) : 0;

            $summary[$category] = [
                'product_code'        => $bucket['product_code'],
                'gross_revenue_minor' => $scenarioRevenue,
                'fee_count'           => $count,
                'avg_fee_minor'       => $avg,
            ];

            $totalRevenue += $scenarioRevenue;
        }

        $distinctUsers = count($allUsers);
        $arpu = $distinctUsers > 0 ? (int) round($totalRevenue / $distinctUsers) : 0;

        return new ScenarioMetrics(
            byCategory: $summary,
            totalGrossRevenueMinor: $totalRevenue,
            arpuMinor: $arpu,
            grossMarginPct: 0.0,
        );
    }
}
