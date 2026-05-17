<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Enums\FeeFormula;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\ValueObjects\FeeBreakdown;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use stdClass;

class PricingResolver
{
    public function resolve(
        string $category,
        int $amountMinor,
        string $currency,
        ?int $userId = null,
        ?string $geo = null,
        ?string $channel = null,
        ?Carbon $at = null,
    ): ?FeeBreakdown {
        $result = $this->resolveWithRule($category, $amountMinor, $currency, $userId, $geo, $channel, $at);

        return $result !== null ? $result[0] : null;
    }

    /**
     * Resolve the fee for a candidate transaction while substituting per-rule
     * config overrides. Used by the scenario simulator: the rule-matching SQL
     * remains identical, but if the winning rule's id appears in $overrides,
     * the override values are merged on top of the live config before compute().
     *
     * Returns null when no rule matches.
     *
     * @param array<int, array<string, mixed>> $overrides keyed by pricing_rule_id
     */
    public function resolveWithOverrides(
        string $category,
        int $amountMinor,
        string $currency,
        array $overrides,
        ?int $userId = null,
        ?string $geo = null,
        ?string $channel = null,
        ?Carbon $at = null,
    ): ?FeeBreakdown {
        $at ??= Carbon::now();

        $row = $this->queryWinningRule($category, $userId, $geo, $channel, $at);
        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = json_decode((string) $row->config, true) ?? [];

        $ruleId = (int) $row->id;
        if (isset($overrides[$ruleId]) && is_array($overrides[$ruleId])) {
            $config = array_replace($config, $overrides[$ruleId]);
        }

        return $this->compute(
            formula: FeeFormula::from((string) $row->formula),
            config: $config,
            amountMinor: $amountMinor,
            currency: $currency,
            userId: $userId,
            productCode: (string) $row->product_code,
            at: $at,
        );
    }

    /**
     * Like resolve(), but also returns the winning PricingRule Eloquent model.
     * Use this when you need to pass the rule to FeeEventRecorder::record().
     *
     * @return array{FeeBreakdown, PricingRule}|null
     */
    public function resolveWithRule(
        string $category,
        int $amountMinor,
        string $currency,
        ?int $userId = null,
        ?string $geo = null,
        ?string $channel = null,
        ?Carbon $at = null,
    ): ?array {
        $at ??= Carbon::now();

        $row = $this->queryWinningRule($category, $userId, $geo, $channel, $at);

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = json_decode((string) $row->config, true) ?? [];

        $breakdown = $this->compute(
            formula: FeeFormula::from((string) $row->formula),
            config: $config,
            amountMinor: $amountMinor,
            currency: $currency,
            userId: $userId,
            productCode: (string) $row->product_code,
            at: $at,
        );

        if ($breakdown === null) {
            return null;
        }

        /** @var PricingRule $rule */
        $rule = PricingRule::find((int) $row->id);

        return [$breakdown, $rule];
    }

    private function queryWinningRule(
        string $category,
        ?int $userId,
        ?string $geo,
        ?string $channel,
        Carbon $at,
    ): ?stdClass {
        $query = DB::table('pricing_rules as r')
            ->join('pricing_products as p', 'p.id', '=', 'r.product_id')
            ->where('p.category', $category)
            ->where('r.status', 'active')
            ->where(function ($q) use ($at): void {
                $q->whereNull('r.effective_from')->orWhere('r.effective_from', '<=', $at);
            })
            ->where(function ($q) use ($at): void {
                $q->whereNull('r.effective_to')->orWhere('r.effective_to', '>', $at);
            });

        if ($geo !== null) {
            $query->where(function ($q) use ($geo): void {
                $q->whereNull('r.geo_scope')
                    ->orWhereRaw('JSON_CONTAINS(r.geo_scope, ?)', [json_encode($geo)]);
            });
        }

        if ($channel !== null) {
            $query->where(function ($q) use ($channel): void {
                $q->whereNull('r.channel')->orWhere('r.channel', '=', $channel);
            });
        }

        if ($userId !== null) {
            $query->where(function ($q) use ($userId): void {
                $q->whereNull('r.segment_id')
                    ->orWhereExists(function ($sub) use ($userId): void {
                        $sub->select(DB::raw(1))
                            ->from('segment_memberships as sm')
                            ->whereColumn('sm.segment_id', 'r.segment_id')
                            ->where('sm.user_id', $userId)
                            ->where(function ($w): void {
                                $w->whereNull('sm.expires_at')->orWhere('sm.expires_at', '>', now());
                            });
                    });
            });
        } else {
            $query->whereNull('r.segment_id');
        }

        /** @var stdClass|null $row */
        $row = $query
            ->orderByDesc('r.priority')
            ->orderByDesc('r.id')
            ->select(['r.*', 'p.code as product_code'])
            ->first();

        return $row;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function compute(
        FeeFormula $formula,
        array $config,
        int $amountMinor,
        string $currency,
        ?int $userId,
        string $productCode,
        Carbon $at,
    ): ?FeeBreakdown {
        $fixed = 0;
        $percentage = 0;

        switch ($formula) {
            case FeeFormula::Fixed:
                $fixed = (int) ($config['fixed_minor'] ?? 0);
                break;

            case FeeFormula::Percentage:
                $percentage = $this->bpsOf($amountMinor, (int) ($config['bps'] ?? 0));
                break;

            case FeeFormula::Hybrid:
                $fixed = (int) ($config['fixed_minor'] ?? 0);
                $percentage = $this->bpsOf($amountMinor, (int) ($config['bps'] ?? 0));
                break;

            case FeeFormula::Tiered:
                /** @var array<int, array<string, int>> $bands */
                $bands = $config['bands'] ?? [];
                [$fixed, $percentage] = $this->computeTiered($bands, $amountMinor);
                break;

            case FeeFormula::Volume:
                $freeCount = (int) ($config['free_count'] ?? 0);
                $windowDays = (int) ($config['window_days'] ?? 0);
                $count = (int) DB::table('fee_events')
                    ->where('user_id', $userId)
                    ->where('product_code', $productCode)
                    ->where('assessed_at', '>=', $at->copy()->subDays($windowDays))
                    ->count();

                if ($userId !== null && $count < $freeCount) {
                    return FeeBreakdown::zero($currency);
                }

                $fixed = (int) ($config['fixed_minor'] ?? 0);
                $percentage = $this->bpsOf($amountMinor, (int) ($config['bps'] ?? 0));
                break;

            case FeeFormula::TimeWindow:
                /** @var array<int, array<string, mixed>> $windows */
                $windows = $config['windows'] ?? [];
                $matched = null;
                foreach ($windows as $window) {
                    $cron = (string) ($window['cron'] ?? '');
                    if ($cron === '') {
                        continue;
                    }
                    if (CronExpression::factory($cron)->isDue($at)) {
                        $matched = $window;
                        break;
                    }
                }

                if ($matched === null) {
                    return null;
                }

                $fixed = (int) ($matched['fixed_minor'] ?? 0);
                $percentage = $this->bpsOf($amountMinor, (int) ($matched['bps'] ?? 0));
                break;
        }

        $capMin = (int) ($config['cap_min_minor'] ?? 0);
        $capMax = (int) ($config['cap_max_minor'] ?? 0);
        $discountBps = (int) ($config['discount_bps'] ?? 0);
        $discount = $discountBps > 0
            ? (int) round(($fixed + $percentage) * $discountBps / 10_000)
            : 0;

        return new FeeBreakdown(
            fixedMinor: $fixed,
            percentageMinor: $percentage,
            fxSpreadMinor: 0,
            currency: $currency,
            capMinMinor: $capMin,
            capMaxMinor: $capMax,
            discountMinor: $discount,
        );
    }

    /**
     * @param array<int, array<string, int>> $bands
     * @return array{0:int,1:int} [fixed_total, percentage_total]
     */
    private function computeTiered(array $bands, int $amountMinor): array
    {
        if ($bands === []) {
            return [0, 0];
        }

        usort($bands, static fn ($a, $b) => ((int) $a['threshold_minor']) <=> ((int) $b['threshold_minor']));

        $fixedTotal = 0;
        $pctTotal = 0;

        for ($i = 0; $i < count($bands); $i++) {
            $threshold = (int) $bands[$i]['threshold_minor'];
            if ($amountMinor <= $threshold) {
                break;
            }
            $nextThreshold = isset($bands[$i + 1]) ? (int) $bands[$i + 1]['threshold_minor'] : PHP_INT_MAX;
            $sliceTop = min($amountMinor, $nextThreshold);
            $slice = max(0, $sliceTop - $threshold);

            $bps = (int) ($bands[$i]['bps'] ?? 0);
            $fixed = (int) ($bands[$i]['fixed_minor'] ?? 0);

            $pctTotal += $this->bpsOf($slice, $bps);
            $fixedTotal += $fixed;
        }

        return [$fixedTotal, $pctTotal];
    }

    private function bpsOf(int $amountMinor, int $bps): int
    {
        if ($bps === 0 || $amountMinor === 0) {
            return 0;
        }

        return (int) round($amountMinor * $bps / 10_000);
    }
}
