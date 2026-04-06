<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\MtnMomoTransaction;
use Filament\Pages\Page;

class PayoutApprovalQueue extends Page
{
    protected static ?string $slug = 'payout-queue';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Finance & Reconciliation';

    protected static ?string $navigationLabel = 'Payout Queue';

    protected static string $view = 'filament.admin.pages.payout-approval-queue';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('approve-adjustments') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MtnMomoTransaction::where('status', 'pending')
            ->where('type', 'disbursement')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\PendingPayoutsWidget::class,
        ];
    }
}
