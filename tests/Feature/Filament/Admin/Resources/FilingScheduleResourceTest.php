<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Domain\RegTech\Enums\Jurisdiction;
use App\Filament\Admin\Resources\FilingScheduleResource;

it('formats empty filing schedule jurisdiction form state safely', function (): void {
    expect(FilingScheduleResource::formatJurisdictionState(null))->toBe('')
        ->and(FilingScheduleResource::formatJurisdictionState('US'))->toBe('US')
        ->and(FilingScheduleResource::formatJurisdictionState(Jurisdiction::UK))->toBe('UK');
});
