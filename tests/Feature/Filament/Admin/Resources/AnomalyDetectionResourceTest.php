<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AnomalyDetectionResource;

it('does not fail assigning anomalies when the fraud analyst role is absent', function (): void {
    expect(AnomalyDetectionResource::fraudAnalystOptions())->toBe([]);
});
