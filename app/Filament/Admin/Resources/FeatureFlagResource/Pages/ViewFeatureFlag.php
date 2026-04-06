<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FeatureFlagResource\Pages;

use App\Filament\Admin\Resources\FeatureFlagResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFeatureFlag extends ViewRecord
{
    protected static string $resource = FeatureFlagResource::class;
}
