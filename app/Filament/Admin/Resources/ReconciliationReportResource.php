<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ReconciliationReportResource\Pages;
use App\Filament\Admin\Resources\ReconciliationReportResource\Widgets\ReconciliationDiscrepancyWidget;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationReportResource extends Resource
{
    use RespectsModuleVisibility;

    protected static ?string $model = null;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Finance & Reconciliation';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Reconciliation Reports';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-transactions') ?? false;
    }

    public static function getModelLabel(): string
    {
        return 'Reconciliation Report';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Reconciliation Reports';
    }

    public static function getHeaderActions(): array
    {
        return [
            Action::make('runReconciliation')
                ->label('Run Reconciliation')
                ->icon('heroicon-o-play-circle')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Run Daily Reconciliation')
                ->modalDescription('This will reconcile all account balances against external custodian records. It may take several minutes.')
                ->visible(fn () => auth()->user()?->can('approve-adjustments') ?? false)
                ->action(function (): void {
                    Artisan::queue('reconciliation:daily', ['--force' => true]);

                    Notification::make()
                        ->title('Reconciliation queued')
                        ->body('The reconciliation job has been dispatched. Results will appear here when complete.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('date')
                        ->label('Date')
                        ->sortable()
                        ->searchable(),

                    Tables\Columns\TextColumn::make('accounts_checked')
                        ->label('Accounts')
                        ->numeric()
                        ->sortable(),

                    Tables\Columns\TextColumn::make('discrepancies_found')
                        ->label('Discrepancies')
                        ->numeric()
                        ->sortable()
                        ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                        ->icon(fn ($state) => $state > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle'),

                    Tables\Columns\TextColumn::make('total_discrepancy_amount')
                        ->label('Total Discrepancy')
                        ->money('USD')
                        ->getStateUsing(fn ($record) => $record['total_discrepancy_amount'] / 100)
                        ->sortable(),

                    Tables\Columns\TextColumn::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(
                            fn (string $state): string => match ($state) {
                                'completed'   => 'success',
                                'failed'      => 'danger',
                                'in_progress' => 'warning',
                                default       => 'gray',
                            }
                        ),

                    Tables\Columns\TextColumn::make('duration_minutes')
                        ->label('Duration')
                        ->numeric()
                        ->suffix(' min')
                        ->sortable(),
                ]
            )
            ->defaultSort('date', 'desc')
            ->actions(
                [
                    Tables\Actions\Action::make('view')
                        ->label('View Report')
                        ->icon('heroicon-m-eye')
                        ->modalHeading('Reconciliation Report Details')
                        ->modalContent(
                            function ($record): string {
                                return view(
                                    'filament.admin.resources.reconciliation-report-details',
                                    [
                                        'report' => $record,
                                    ]
                                )->render();
                            }
                        )
                        ->modalWidth('7xl'),

                    Tables\Actions\Action::make('download')
                        ->label('Download')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->action(
                            function ($record) {
                                $filename = "reconciliation-{$record['date']}.json";

                                return response()->json($record)
                                    ->header('Content-Disposition', "attachment; filename={$filename}");
                            }
                        ),
                ]
            )
            ->bulkActions(
                [
                    Tables\Actions\BulkAction::make('exportCsv')
                        ->label('Export CSV')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->action(function ($records): StreamedResponse {
                            $filename = 'reconciliation-report-' . now()->format('Y-m-d-His') . '.csv';

                            return response()->streamDownload(function () use ($records): void {
                                $handle = fopen('php://output', 'w');
                                fputcsv($handle, ['Date', 'Accounts Checked', 'Discrepancies Found', 'Total Discrepancy', 'Status', 'Duration (min)']);

                                foreach ($records as $record) {
                                    fputcsv($handle, [
                                        $record['date'] ?? '',
                                        $record['accounts_checked'] ?? 0,
                                        $record['discrepancies_found'] ?? 0,
                                        number_format(($record['total_discrepancy_amount'] ?? 0) / 100, 2),
                                        $record['status'] ?? 'unknown',
                                        $record['duration_minutes'] ?? 0,
                                    ]);
                                }

                                fclose($handle);
                            }, $filename, [
                                'Content-Type'        => 'text/csv',
                                'Content-Disposition' => "attachment; filename={$filename}",
                            ]);
                        })
                        ->deselectRecordsAfterCompletion(),
                ]
            );
    }

    public static function getWidgets(): array
    {
        return [
            ReconciliationDiscrepancyWidget::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReconciliationReports::route('/'),
        ];
    }
}
