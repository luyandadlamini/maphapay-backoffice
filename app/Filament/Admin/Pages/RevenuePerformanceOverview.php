<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Analytics\DTO\WalletRevenueActivityResult;
use App\Domain\Analytics\Services\WalletRevenueActivityMetrics;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Wallet-scoped revenue overview (REQ-REV-001): activity / volume from projections, not recognized revenue.
 *
 * @property Form $form
 */
class RevenuePerformanceOverview extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $navigationGroup = 'Revenue & Performance';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Revenue & performance';

    protected static string $view = 'filament.admin.pages.revenue-performance-overview';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(
            [
                'preset'       => '30d',
                'custom_start' => null,
                'custom_end'   => null,
            ]
        );
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $access = app(BackofficeWorkspaceAccess::class);

        return $access->canAccess('finance', $user)
            || $access->canAccess('platform_administration', $user);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(
                [
                    Forms\Components\Section::make(__('Time range'))
                        ->description(__('Choose a preset or a custom window (capped per REQ-PERF-001).'))
                        ->schema(
                            [
                                Forms\Components\Select::make('preset')
                                    ->label(__('Range'))
                                    ->options(
                                        [
                                            '7d'     => __('Last 7 days'),
                                            '30d'    => __('Last 30 days'),
                                            'mtd'    => __('Month to date'),
                                            'custom' => __('Custom range'),
                                        ]
                                    )
                                    ->default('30d')
                                    ->required()
                                    ->live(),
                                Forms\Components\Grid::make(2)
                                    ->schema(
                                        [
                                            Forms\Components\DatePicker::make('custom_start')
                                                ->label(__('Custom start'))
                                                ->visible(fn (Forms\Get $get): bool => $get('preset') === 'custom')
                                                ->live(),
                                            Forms\Components\DatePicker::make('custom_end')
                                                ->label(__('Custom end'))
                                                ->visible(fn (Forms\Get $get): bool => $get('preset') === 'custom')
                                                ->live()
                                                ->afterOrEqual('custom_start'),
                                        ]
                                    ),
                            ]
                        ),
                ]
            )
            ->statePath('data');
    }

    public function getActivityResult(): WalletRevenueActivityResult
    {
        [$start, $end] = $this->resolvePeriodFromForm();

        return app(WalletRevenueActivityMetrics::class)->forPeriod($start, $end);
    }

    public function reportingCurrencyDisplay(): string
    {
        return (string) config('maphapay.revenue_reporting_currency_display', 'ZAR');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriodFromForm(): array
    {
        $data = is_array($this->data) ? $this->data : [];
        $preset = isset($data['preset']) && is_string($data['preset']) ? $data['preset'] : '30d';

        $end = Carbon::now()->endOfDay();

        return match ($preset) {
            '7d'     => [Carbon::now()->copy()->subDays(7)->startOfDay(), $end],
            '30d'    => [Carbon::now()->copy()->subDays(30)->startOfDay(), $end],
            'mtd'    => [Carbon::now()->copy()->startOfMonth()->startOfDay(), $end],
            'custom' => $this->resolveCustomRange($data, $end),
            default  => [Carbon::now()->copy()->subDays(30)->startOfDay(), $end],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveCustomRange(array $data, Carbon $end): array
    {
        $startRaw = $data['custom_start'] ?? null;
        $endRaw = $data['custom_end'] ?? null;

        if ($startRaw === null || $startRaw === '' || $endRaw === null || $endRaw === '') {
            return [Carbon::now()->copy()->subDays(30)->startOfDay(), $end];
        }

        $start = Carbon::parse((string) $startRaw)->startOfDay();
        $customEnd = Carbon::parse((string) $endRaw)->endOfDay();

        return [$start, $customEnd];
    }
}
