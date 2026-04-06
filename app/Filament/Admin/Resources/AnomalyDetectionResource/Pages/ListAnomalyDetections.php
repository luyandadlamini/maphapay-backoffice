<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AnomalyDetectionResource\Pages;

use App\Filament\Admin\Resources\AnomalyDetectionResource;
use App\Filament\Admin\Widgets\AnomalyTrendWidget;
use Filament\Resources\Pages\ListRecords;

class ListAnomalyDetections extends ListRecords
{
    protected static string $resource = AnomalyDetectionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\FraudResolutionRateWidget::class,
            AnomalyTrendWidget::class,
        ];
    }
}
