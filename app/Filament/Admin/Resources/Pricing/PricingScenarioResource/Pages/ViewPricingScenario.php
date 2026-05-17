<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingScenarioResource\Pages;

use App\Domain\Pricing\Models\PricingScenario;
use App\Filament\Admin\Resources\Pricing\PricingScenarioResource;
use App\Jobs\Pricing\RunPricingScenarioJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPricingScenario extends ViewRecord
{
    protected static string $resource = PricingScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_simulation')
                ->label('Run simulation')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var PricingScenario $scenario */
                    $scenario = $this->getRecord();

                    dispatch(new RunPricingScenarioJob((string) $scenario->getKey()));

                    Notification::make()
                        ->title('Simulation queued')
                        ->body("Scenario \"{$scenario->name}\" has been queued. Refresh to see results.")
                        ->success()
                        ->send();
                }),

            Actions\Action::make('compare_actuals')
                ->label('Compare with actuals')
                ->icon('heroicon-o-chart-bar')
                ->color('info')
                ->visible(fn (): bool => $this->getRecord()->last_run_result !== null)
                ->modalHeading(fn (): string => 'Simulation results: ' . $this->getRecord()->name)
                ->form(function (): array {
                    /** @var PricingScenario $scenario */
                    $scenario = $this->getRecord();

                    return PricingScenarioResource::buildResultSummaryForm($scenario);
                })
                ->fillForm(function (): array {
                    /** @var PricingScenario $scenario */
                    $scenario = $this->getRecord();

                    return PricingScenarioResource::fillResultSummary($scenario);
                })
                ->modalSubmitAction(false),

            Actions\DeleteAction::make(),
        ];
    }
}
