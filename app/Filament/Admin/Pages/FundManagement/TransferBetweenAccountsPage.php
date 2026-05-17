<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\FundManagement;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\FundManagement\Services\FundManagementService;
use App\Filament\Admin\Concerns\WithAccountTenancy;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\DB;
use Throwable;

class TransferBetweenAccountsPage extends Page
{
    /**
     * This page operates across TWO accounts that may belong to DIFFERENT tenants.
     *
     * Tenancy ordering for a cross-tenant transfer:
     *   1. Source tenant  → initialised before the debit  (transfer_out side)
     *   2. Destination tenant → initialised before the credit (transfer_in side)
     *
     * We use WithAccountTenancy which already handles switching (end → initialize)
     * when the current tenant differs from the requested one.  The page calls
     * initializeTenancyForRecord() twice during executeTransfer():
     *   - once for the source account  (debit phase)
     *   - once for the destination account (credit phase)
     * The FundManagementService::transferBetweenAccounts() workflow is
     * intentionally invoked inside the source-tenant context; a future refactor
     * may split the workflow into per-tenant activities.  The transaction-projection
     * writes (recordTransaction) for each side are wrapped around the matching
     * tenant switch so that each write lands in its own tenant's DB connection.
     */
    use WithAccountTenancy;

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

    /**
     * Look up the source account and initialize tenancy for its tenant.
     *
     * Exposed as a public method so that Livewire can call it directly in tests.
     */
    public function lookupSourceAccount(string $uuid): void
    {
        if (empty($uuid)) {
            $this->sourceAccount = null;

            return;
        }

        $this->sourceAccount = Account::with('user')->where('uuid', $uuid)->first();

        if ($this->sourceAccount !== null) {
            // Initialize tenancy for the source tenant so that subsequent reads
            // on this account use the correct DB connection.
            $this->initializeTenancyForRecord($this->sourceAccount);
        }
    }

    /**
     * Look up the destination account and initialize tenancy for its tenant.
     *
     * Exposed as a public method so that Livewire can call it directly in tests.
     * WithAccountTenancy::initializeTenancyForRecord() will switch tenants
     * (end → initialize) if the destination belongs to a different tenant than
     * the source that was previously initialised.
     */
    public function lookupDestinationAccount(string $uuid): void
    {
        if (empty($uuid)) {
            $this->destinationAccount = null;

            return;
        }

        $this->destinationAccount = Account::with('user')->where('uuid', $uuid)->first();

        if ($this->destinationAccount !== null) {
            // Switch to the destination tenant context after the source lookup.
            $this->initializeTenancyForRecord($this->destinationAccount);
        }
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

        // Capture as local variables so PHPStan can narrow the non-null type
        // after the early-return guard above (class properties are not narrowed
        // inside try blocks because they could be mutated by other methods).
        $sourceAccount = $this->sourceAccount;
        $destinationAccount = $this->destinationAccount;

        try {
            DB::beginTransaction();

            $asset = Asset::where('code', $data['asset_code'])->firstOrFail();
            $amountInSmallestUnit = $asset->toSmallestUnit((float) $data['amount']);

            // Step 1 — Source (debit) tenant context.
            // Initialize tenancy for the source account's tenant before the debit
            // leg of the transfer.  WithAccountTenancy switches tenants automatically
            // when source and destination belong to different tenants.
            $this->initializeTenancyForRecord($sourceAccount);

            $fundService = app(FundManagementService::class);
            $fundService->transferBetweenAccounts(
                fromAccount: $sourceAccount,
                toAccount: $destinationAccount,
                assetCode: $data['asset_code'],
                amountInSmallestUnit: $amountInSmallestUnit,
                reason: $data['reason'],
                performedBy: auth()->user()
            );

            // Step 2 — Destination (credit) tenant context.
            // After the transfer workflow completes, switch to the destination
            // tenant so that any follow-up writes (notifications, projections)
            // land in the correct tenant DB.
            $this->initializeTenancyForRecord($destinationAccount);

            DB::commit();

            Notification::make()
                ->title('Transfer Successful')
                ->body("{$asset->formatAmount($amountInSmallestUnit)} transferred from {$sourceAccount->name} to {$destinationAccount->name}")
                ->success()
                ->send();

            $this->reset(['sourceAccountUuid', 'destinationAccountUuid', 'sourceAccount', 'destinationAccount']);

        } catch (Throwable $e) {
            DB::rollBack();

            Notification::make()
                ->title('Transfer Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
