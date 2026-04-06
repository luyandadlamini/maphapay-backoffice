<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Monitoring\Services\ProjectorHealthService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

class ProjectorHealthDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Projector Health';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.admin.pages.projector-health-dashboard';

    public ?array $healthData = [];

    public function mount(): void
    {
        $this->loadHealthData();
    }

    public function loadHealthData(): void
    {
        $this->healthData = app(ProjectorHealthService::class)->getAllProjectorStatus();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->hasRole('super-admin');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $this->loadHealthData();
                    Notification::make()
                        ->title('Refreshed')
                        ->success()
                        ->send();
                }),

            Action::make('rebuildAll')
                ->label('Rebuild All Projectors')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Rebuild All Projectors')
                ->modalDescription('This will rebuild ALL projectors in the system. This is a nuclear option and may take considerable time.')
                ->modalSubmitActionLabel('Yes, rebuild all')
                ->action(function (): void {
                    Artisan::call('event-sourcing:replay');

                    Notification::make()
                        ->title('Projector rebuild queued')
                        ->body('All projectors are being rebuilt in the background.')
                        ->warning()
                        ->send();
                }),
        ];
    }
}
