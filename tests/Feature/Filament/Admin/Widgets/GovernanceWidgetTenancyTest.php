<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\PollResource\Widgets\GovernanceStatsWidget;
use App\Filament\Admin\Resources\PollResource\Widgets\PollActivityChartWidget;
use Stancl\Tenancy\Tenancy;

afterEach(function (): void {
    app(Tenancy::class)->end();
});

it('does not query governance widgets without active tenancy', function (): void {
    expect(app(Tenancy::class)->initialized)->toBeFalse()
        ->and((new class extends GovernanceStatsWidget
        {
            public function visibleStats(): array
            {
                return $this->getStats();
            }
        })->visibleStats())->toHaveCount(6)
        ->and((new class extends PollActivityChartWidget
        {
            public function visibleData(): array
            {
                return $this->getData();
            }
        })->visibleData()['datasets'][0]['data'])->toHaveCount(30);
});
