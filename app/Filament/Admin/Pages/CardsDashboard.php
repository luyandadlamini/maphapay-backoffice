<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use Filament\Pages\Page;

class CardsDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Cards Operations';

    protected static ?string $title = 'Cards Operations';

    protected static ?string $navigationGroup = 'Cards';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.admin.pages.cards-dashboard';

    protected static ?string $slug = 'cards-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(['super-admin', 'compliance-manager', 'operations-l2', 'fraud-analyst', 'support-l1']);
    }
}
