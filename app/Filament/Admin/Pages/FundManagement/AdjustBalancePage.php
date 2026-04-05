<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\FundManagement;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\FundManagement\Services\FundManagementService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdjustBalancePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Adjust Balance';

    protected static ?string $title = 'Manual Balance Adjustment';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.admin.pages.fund-management.adjust-balance';

    public ?string $accountUuid = null;

    public ?Account $selectedAccount = null;

    public array $availableAssets = [];

    public function mount(): void
    {
        $this->loadAvailableAssets();
    }

    protected function loadAvailableAssets(): void
    {
        $assets = Asset::active()->get();

        foreach ($assets as $asset) {
            $this->availableAssets[$asset->code] = [
                'code'      => $asset->code,
                'name'      => $asset->name,
                'type'      => $asset->type,
                'precision' => $asset->precision,
            ];
        }
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('adjust')
                ->label('Apply Adjustment')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->form($this->getAdjustFormSchema())
                ->action(fn (array $data) => $this->adjustBalance($data))
                ->disabled(fn () => ! $this->selectedAccount)
                ->requiresConfirmation()
                ->modalHeading('Confirm Balance Adjustment')
                ->modalDescription(fn () => $this->selectedAccount
                    ? "Adjust balance for account: {$this->selectedAccount->name}"
                    : 'Select an account first')
                ->modalAlignment(Alignment::Center),
        ];
    }

    protected function getAdjustFormSchema(): array
    {
        return [
            \Filament\Forms\Components\Section::make('Account Selection')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('account_uuid')
                        ->label('Account UUID')
                        ->required()
                        ->placeholder('Enter account UUID')
                        ->helperText('Search for the account to adjust')
                        ->columnSpanFull()
                        ->reactive()
                        ->afterStateUpdated(fn ($state, callable $set) => $this->lookupAccount($state, $set)),
                ]),

            \Filament\Forms\Components\Section::make('Selected Account')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('account_name')
                        ->label('Account Name')
                        ->content(fn () => $this->selectedAccount?->name ?? 'Not found'),

                    \Filament\Forms\Components\Placeholder::make('account_user')
                        ->label('User')
                        ->content(fn () => $this->selectedAccount?->user?->name ?? 'Unknown'),
                ])->visible(fn () => $this->selectedAccount),

            \Filament\Forms\Components\Section::make('Adjustment Details')
                ->schema([
                    \Filament\Forms\Components\Select::make('asset_code')
                        ->label('Currency')
                        ->options(array_column($this->availableAssets, 'name', 'code'))
                        ->default('USD')
                        ->required(),

                    \Filament\Forms\Components\Select::make('adjustment_type')
                        ->label('Adjustment Type')
                        ->options([
                            'credit' => 'Credit (+) - Add funds',
                            'debit'  => 'Debit (-) - Remove funds',
                        ])
                        ->required(),

                    \Filament\Forms\Components\TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->required()
                        ->minValue(0.01),

                    \Filament\Forms\Components\Select::make('reason_category')
                        ->label('Reason Category')
                        ->options([
                            'error'      => 'Error Correction',
                            'goodwill'   => 'Goodwill Payment',
                            'regulatory' => 'Regulatory Requirement',
                            'refund'     => 'Refund',
                            'other'      => 'Other',
                        ])
                        ->required(),

                    \Filament\Forms\Components\Textarea::make('description')
                        ->label('Description')
                        ->placeholder('Detailed explanation for this adjustment...')
                        ->required()
                        ->columnSpanFull(),
                ])->columns(2),
        ];
    }

    protected function lookupAccount(string $uuid, callable $set): void
    {
        if (empty($uuid)) {
            $this->selectedAccount = null;

            return;
        }

        $this->selectedAccount = Account::with('user')->where('uuid', $uuid)->first();
    }

    protected function adjustBalance(array $data): void
    {
        if (! $this->selectedAccount) {
            Notification::make()
                ->title('Account Not Found')
                ->body('Please select a valid account first.')
                ->danger()
                ->send();

            return;
        }

        try {
            DB::beginTransaction();

            $asset = Asset::where('code', $data['asset_code'])->firstOrFail();
            $amountInSmallestUnit = $asset->toSmallestUnit((float) $data['amount']);

            if ($data['adjustment_type'] === 'debit') {
                $amountInSmallestUnit = -$amountInSmallestUnit;
            }

            $fundService = app(FundManagementService::class);
            $fundService->adjustBalance(
                account: $this->selectedAccount,
                assetCode: $data['asset_code'],
                amountInSmallestUnit: $amountInSmallestUnit,
                reasonCategory: $data['reason_category'],
                description: $data['description'],
                performedBy: auth()->user()
            );

            DB::commit();

            $prefix = $data['adjustment_type'] === 'credit' ? '+' : '-';
            Notification::make()
                ->title('Adjustment Successful')
                ->body("{$prefix}{$asset->formatAmount(abs($amountInSmallestUnit))} has been applied to account {$this->selectedAccount->name}")
                ->success()
                ->send();

            $this->reset(['accountUuid', 'selectedAccount']);

        } catch (Throwable $e) {
            DB::rollBack();

            Notification::make()
                ->title('Adjustment Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
