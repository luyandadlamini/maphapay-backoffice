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

class TransferBetweenAccountsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Transfer';

    protected static ?string $title = 'Transfer Between Accounts';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.admin.pages.fund-management.transfer-between-accounts';

    public ?string $sourceAccountUuid = null;
    public ?string $destinationAccountUuid = null;
    public ?Account $sourceAccount = null;
    public ?Account $destinationAccount = null;
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
                'code' => $asset->code,
                'name' => $asset->name,
                'type' => $asset->type,
                'precision' => $asset->precision,
            ];
        }
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('transfer')
                ->label('Execute Transfer')
                ->icon('heroicon-o-arrows-right-left')
                ->color('primary')
                ->form($this->getTransferFormSchema())
                ->action(fn (array $data) => $this->executeTransfer($data))
                ->disabled(fn () => ! $this->sourceAccount || ! $this->destinationAccount)
                ->requiresConfirmation()
                ->modalHeading('Confirm Transfer')
                ->modalDescription(fn () => $this->sourceAccount && $this->destinationAccount
                    ? "Transfer from {$this->sourceAccount->name} to {$this->destinationAccount->name}"
                    : 'Select both accounts first')
                ->modalAlignment(Alignment::Center),
        ];
    }

    protected function getTransferFormSchema(): array
    {
        return [
            \Filament\Forms\Components\Section::make('Source Account')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('source_uuid')
                        ->label('Source Account UUID')
                        ->required()
                        ->placeholder('Enter source account UUID')
                        ->columnSpanFull()
                        ->reactive()
                        ->afterStateUpdated(fn ($state, callable $set) => $this->lookupSourceAccount($state)),
                ]),

            \Filament\Forms\Components\Section::make('Destination Account')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('destination_uuid')
                        ->label('Destination Account UUID')
                        ->required()
                        ->placeholder('Enter destination account UUID')
                        ->columnSpanFull()
                        ->reactive()
                        ->afterStateUpdated(fn ($state, callable $set) => $this->lookupDestinationAccount($state)),
                ])->columns(2),

            \Filament\Forms\Components\Section::make('Transfer Details')
                ->schema([
                    \Filament\Forms\Components\Select::make('asset_code')
                        ->label('Currency')
                        ->options(array_column($this->availableAssets, 'name', 'code'))
                        ->default('USD')
                        ->required(),

                    \Filament\Forms\Components\TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->required()
                        ->minValue(0.01),

                    \Filament\Forms\Components\TextInput::make('reason')
                        ->label('Reason / Description')
                        ->placeholder('Reason for transfer')
                        ->required(),
                ])->columns(2),
        ];
    }

    protected function lookupSourceAccount(string $uuid): void
    {
        if (empty($uuid)) {
            $this->sourceAccount = null;
            return;
        }

        $this->sourceAccount = Account::with('user')->where('uuid', $uuid)->first();
    }

    protected function lookupDestinationAccount(string $uuid): void
    {
        if (empty($uuid)) {
            $this->destinationAccount = null;
            return;
        }

        $this->destinationAccount = Account::with('user')->where('uuid', $uuid)->first();
    }

    protected function executeTransfer(array $data): void
    {
        if (! $this->sourceAccount || ! $this->destinationAccount) {
            Notification::make()
                ->title('Accounts Not Found')
                ->body('Please select valid source and destination accounts.')
                ->danger()
                ->send();

            return;
        }

        if ($this->sourceAccount->uuid === $this->destinationAccount->uuid) {
            Notification::make()
                ->title('Invalid Transfer')
                ->body('Source and destination accounts cannot be the same.')
                ->danger()
                ->send();

            return;
        }

        if ($this->sourceAccount->frozen) {
            Notification::make()
                ->title('Source Account Frozen')
                ->body('Cannot transfer from a frozen account.')
                ->danger()
                ->send();

            return;
        }

        if ($this->destinationAccount->frozen) {
            Notification::make()
                ->title('Destination Account Frozen')
                ->body('Cannot transfer to a frozen account.')
                ->danger()
                ->send();

            return;
        }

        try {
            DB::beginTransaction();

            $asset = Asset::where('code', $data['asset_code'])->firstOrFail();
            $amountInSmallestUnit = $asset->toSmallestUnit((float) $data['amount']);

            $fundService = app(FundManagementService::class);
            $fundService->transferBetweenAccounts(
                fromAccount: $this->sourceAccount,
                toAccount: $this->destinationAccount,
                assetCode: $data['asset_code'],
                amountInSmallestUnit: $amountInSmallestUnit,
                reason: $data['reason'],
                performedBy: auth()->user()
            );

            DB::commit();

            Notification::make()
                ->title('Transfer Successful')
                ->body("{$asset->formatAmount($amountInSmallestUnit)} transferred from {$this->sourceAccount->name} to {$this->destinationAccount->name}")
                ->success()
                ->send();

            $this->reset(['sourceAccountUuid', 'destinationAccountUuid', 'sourceAccount', 'destinationAccount']);

        } catch (\Throwable $e) {
            DB::rollBack();

            Notification::make()
                ->title('Transfer Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
