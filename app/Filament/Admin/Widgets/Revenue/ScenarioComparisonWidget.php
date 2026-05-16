<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Revenue;

use App\Domain\Pricing\Models\PricingScenario;
use App\Domain\Pricing\Models\RevenueDailyRollup;
use Filament\Widgets\Widget;

class ScenarioComparisonWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.revenue.scenario-comparison-widget';

    protected static ?string $heading = 'Scenario vs Actuals (90 days)';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $actual90Minor = (int) RevenueDailyRollup::where('date', '>=', now()->subDays(90)->toDateString())
            ->sum('gross_revenue_minor');

        $scenarios = PricingScenario::whereNotNull('last_run_result')
            ->latest('last_run_at')
            ->limit(3)
            ->get();

        $rows = $scenarios->map(function (PricingScenario $scenario) use ($actual90Minor): array {
            /** @var array<string, mixed> $result */
            $result = $scenario->last_run_result ?? [];

            $scenarioMinor = (int) ($result['total_gross_revenue_minor'] ?? 0);
            $deltaMinor = $scenarioMinor - $actual90Minor;

            return [
                'name'             => $scenario->name,
                'last_run_at'      => $scenario->last_run_at?->format('Y-m-d H:i'),
                'scenario_revenue' => number_format($scenarioMinor / 100, 2),
                'actual_revenue'   => number_format($actual90Minor / 100, 2),
                'delta'            => number_format(abs($deltaMinor) / 100, 2),
                'delta_positive'   => $deltaMinor >= 0,
            ];
        })->toArray();

        return [
            'rows'     => $rows,
            'actual90' => number_format($actual90Minor / 100, 2),
        ];
    }
}
