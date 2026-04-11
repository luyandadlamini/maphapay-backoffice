<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Monitoring\Services\ProjectorHealthService;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ProjectorHealthDashboard extends Page
{
    use HasBackofficeWorkspace;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Projector Health';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.admin.pages.projector-health-dashboard';

    protected static string $backofficeWorkspace = 'platform_administration';

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
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
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
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Reason for rebuild request')
                        ->required()
                        ->minLength(10),
                ])
                ->modalHeading('Rebuild All Projectors')
                ->modalDescription('This will submit a controlled rebuild-all request for approval before any replay runs.')
                ->modalSubmitActionLabel('Submit rebuild request')
                ->action(function (array $data): void {
                    $this->requestRebuildAll((string) $data['reason']);
                }),
        ];
    }

    public function requestRebuildAll(string $reason): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());

        app(AdminActionGovernance::class)->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.projectors.rebuild_all',
            reason: $reason,
            targetType: 'projector',
            targetIdentifier: 'all',
            payload: [
                'scope' => 'all_projectors',
                'artisan_command' => 'event-sourcing:replay',
            ],
            metadata: [
                'actor_email' => auth()->user()->email ?? 'system',
            ],
        );

        Notification::make()
            ->title('Projector rebuild request submitted')
            ->body('This replay now requires approval before execution.')
            ->warning()
            ->send();
    }
}
