<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\AnomalyTrendWidget;
use App\Filament\Admin\Resources\ExchangeRateResource\Widgets\ExchangeRateStatsWidget;
use App\Filament\Admin\Widgets\FraudResolutionRateWidget;
use Stancl\Tenancy\Tenancy;

afterEach(function (): void {
    config(['app.env' => 'testing']);
    app(Tenancy::class)->end();
});

it('does not query anomaly widgets without active tenancy', function (): void {
    config(['app.env' => 'production']);

    expect(app(Tenancy::class)->initialized)->toBeFalse()
        ->and((new class extends FraudResolutionRateWidget
        {
            public function visibleStats(): array
            {
                return $this->getStats();
            }
        })->visibleStats())->toHaveCount(3)
        ->and((new class extends AnomalyTrendWidget
        {
            public function visibleStats(): array
            {
                return $this->getStats();
            }
        })->visibleStats())->toHaveCount(4)
        ->and((new class extends ExchangeRateStatsWidget
        {
            public function visibleStats(): array
            {
                return $this->getStats();
            }
        })->visibleStats())->toHaveCount(4);
});
