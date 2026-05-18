<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AssetResource;
use App\Filament\Admin\Resources\BasketAssetResource;
use App\Filament\Admin\Resources\ExchangeRateResource;
use Stancl\Tenancy\Tenancy;

afterEach(function (): void {
    config(['app.env' => 'testing']);
    app(Tenancy::class)->end();
});

it('does not query tenant-backed resources for navigation badges without active tenancy', function (): void {
    config(['app.env' => 'production']);

    expect(app(Tenancy::class)->initialized)->toBeFalse()
        ->and(AssetResource::getNavigationBadge())->toBeNull()
        ->and(BasketAssetResource::getNavigationBadge())->toBeNull()
        ->and(ExchangeRateResource::getNavigationBadge())->toBeNull();
});
