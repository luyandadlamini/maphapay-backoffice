<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReconciliationReportResource\Pages;

use App\Domain\Custodian\Models\CustodianTransfer;
use App\Domain\Custodian\Services\DailyReconciliationService;
use App\Filament\Admin\Resources\ReconciliationReportResource;
use App\Support\Reconciliation\ReconciliationReportDataLoader;
use Exception;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ListReconciliationReports extends ListRecords
{
    protected static string $resource = ReconciliationReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_reconciliation')
                ->label('Run Reconciliation')
                ->icon('heroicon-m-play')
                ->visible(fn (): bool => auth()->user()?->can('approve-adjustments') ?? false)
                ->action(
                    function () {
                        $service = app(DailyReconciliationService::class);

                        try {
                            $service->performDailyReconciliation();

                            $this->notify('success', 'Reconciliation completed successfully');

                            // Refresh the page
                            $this->redirect(static::getResource()::getUrl('index'));
                        } catch (Exception $e) {
                            $this->notify('danger', 'Reconciliation failed: ' . $e->getMessage());
                        }
                    }
                )
                ->requiresConfirmation()
                ->modalHeading('Run Daily Reconciliation')
                ->modalDescription('This will perform a full balance reconciliation for all accounts. Continue?'),
        ];
    }

    public function getTableRecords(): Collection|LengthAwarePaginator
    {
        $reports = app(ReconciliationReportDataLoader::class)->load();

        // Convert to paginator
        $page = request()->get('page', 1);
        $perPage = 10;
        $slice = $reports->slice(($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(
            $slice,
            $reports->count(),
            $perPage,
            $page,
            ['path' => request()->url()]
        );
    }

    protected function getTableQuery(): Builder
    {
        // Reconciliation data is file-based; this stub Builder satisfies Filament's
        // type contract while getTableRecords() supplies the actual data.
        return CustodianTransfer::query()->whereRaw('0=1');
    }
}
