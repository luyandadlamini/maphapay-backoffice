<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Custodian\Services\CustodianHealthMonitor;
use App\Domain\Custodian\Services\CustodianRegistry;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Support\Backoffice\AdminActionGovernance;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class BankOperations extends Page implements HasTable
{
    use HasBackofficeWorkspace;
    use InteractsWithTable;
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Finance & Reconciliation';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Bank Operations Center';

    protected static string $view = 'filament.admin.pages.bank-operations';

    protected static string $backofficeWorkspace = 'finance';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('approve-adjustments') || $user->hasRole('super-admin'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->records($this->getBankOperationsQuery())
            ->columns([
                Tables\Columns\TextColumn::make('custodian')
                    ->label('Bank/Custodian')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Health Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'healthy'   => 'success',
                        'degraded'  => 'warning',
                        'unhealthy' => 'danger',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('overall_failure_rate')
                    ->label('24h Error Rate')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1) . '%')
                    ->color(fn ($state) => (float) $state > 5.0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('availability_24h')
                    ->label('Availability')
                    ->getStateUsing(fn ($record) => $this->get24hAvailability($record['custodian'])),

                Tables\Columns\IconColumn::make('reconciliation_status')
                    ->label('Recon Status')
                    ->options([
                        'heroicon-s-check-circle' => 'synced',
                        'heroicon-m-exclamation-triangle' => 'lagging',
                    ])
                    ->colors([
                        'success' => 'synced',
                        'danger' => 'lagging',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('trigger_reconciliation')
                    ->label('Recon')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for manual reconciliation')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(fn ($record, array $data) => $this->runManualRecon($record['custodian'], $data['reason'])),
                
                Tables\Actions\Action::make('freeze_settlement')
                    ->label('Freeze')
                    ->icon('heroicon-o-pause')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for settlement freeze request')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(fn ($record, array $data) => $this->freezeBankSettlement($record['custodian'], $data['reason'])),
            ]);
    }

    public function getHealthMonitor(): CustodianHealthMonitor
    {
        return app(CustodianHealthMonitor::class);
    }

    public function getCustodianRegistry(): CustodianRegistry
    {
        return app(CustodianRegistry::class);
    }

    protected function getAdminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    protected function getBankOperationsQuery(): Collection
    {
        $monitor = $this->getHealthMonitor();
        $healthData = $monitor->getAllCustodiansHealth();

        return collect($healthData);
    }

    protected function get24hAvailability(string $custodian): string
    {
        $monitor = $this->getHealthMonitor();
        $metrics = $monitor->getAvailabilityMetrics($custodian, 24);
        
        return ($metrics['availability_percentage'] ?? 0) . '%';
    }

    public function runReconciliation(): void
    {
        $this->getAdminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.bank_operations.manual_reconciliation_triggered',
            reason: 'Global reconciliation triggered from bank operations dashboard.',
            auditable: null,
            metadata: [
                'custodian' => 'all',
            ],
            tags: 'backoffice,finance,reconciliation'
        );

        Notification::make()
            ->title('Global reconciliation started')
            ->success()
            ->send();
    }

    public function runManualRecon(string $custodian, string $reason): void
    {
        $this->getAdminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.bank_operations.manual_reconciliation_triggered',
            reason: $reason,
            auditable: null,
            metadata: [
                'custodian' => $custodian,
            ],
            tags: 'backoffice,finance,reconciliation'
        );

        Notification::make()
            ->title("Reconciliation started for {$custodian}")
            ->success()
            ->send();
    }

    public function freezeBankSettlement(string $custodian, string $reason): void
    {
        $this->getAdminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.bank_operations.freeze_settlement',
            reason: $reason,
            targetType: 'custodian',
            targetIdentifier: $custodian,
            payload: [
                'custodian' => $custodian,
                'requested_state' => 'frozen',
            ],
        );

        Notification::make()
            ->title("Settlement freeze request submitted for {$custodian}")
            ->warning()
            ->send();
    }
}
