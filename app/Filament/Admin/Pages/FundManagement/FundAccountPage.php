<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\FundManagement;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\FundManagement\Services\FundManagementService;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Throwable;

class FundAccountPage extends Page
{
    use HasBackofficeWorkspace;

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'Fund Account';

    protected static ?string $title = 'Fund User Account';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.admin.pages.fund-management.fund-account';

    protected static string $backofficeWorkspace = 'finance';

    public ?string $accountUuid = null;

    public ?Account $selectedAccount = null;

    public array $availableAssets = [];

    public static function canAccess(): bool
    {
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

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
            Action::make('fund')
                ->label('Fund Account')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->form($this->getFundFormSchema())
                ->action(fn (array $data) => $this->fundAccount($data))
                ->disabled(fn () => ! $this->selectedAccount)
                ->requiresConfirmation()
                ->modalHeading('Confirm Fund Transfer')
                ->modalDescription(fn () => $this->selectedAccount
                    ? "Transfer funds to account: {$this->selectedAccount->name}"
                    : 'Select an account first')
                ->modalAlignment(Alignment::Center),
        ];
    }

    protected function getFundFormSchema(): array
    {
        return [
            \Filament\Forms\Components\Section::make('Account Selection')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('account_uuid')
                        ->label('Account UUID')
                        ->required()
                        ->placeholder('Enter account UUID')
                        ->helperText('Search for the account to fund')
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

            \Filament\Forms\Components\Section::make('Funding Details')
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

                    \Filament\Forms\Components\Select::make('reason')
                        ->label('Reason')
                        ->options([
                            'testing'          => 'Testing',
                            'refund'           => 'Refund',
                            'compensation'     => 'Compensation',
                            'error_correction' => 'Error Correction',
                            'goodwill'         => 'Goodwill',
                            'other'            => 'Other',
                        ])
                        ->default('testing')
                        ->required(),

                    \Filament\Forms\Components\Textarea::make('notes')
                        ->label('Notes')
                        ->placeholder('Optional notes about this funding...')
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

    /** @param array<string, mixed> $data */
    public function requestFundingApproval(array $data): void
    {
        $this->authorizeWorkspace();

        app(AdminActionGovernance::class)->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.fund_accounts.fund',
            reason: $data['reason'],
            payload: [
                'account_uuid' => $data['account_uuid'],
                'asset_code'   => $data['asset_code'],
                'amount_minor' => (int) round((float) $data['amount'] * 100),
                'notes'        => $data['notes'] ?? null,
            ],
            metadata: [
                'mode' => 'request_approve',
            ],
        );
    }

    /** @param array<string, mixed> $data */
    protected function fundAccount(array $data): void
    {
        if (! $this->selectedAccount) {
            Notification::make()
                ->title('Account Not Found')
                ->body('Please select a valid account first.')
                ->danger()
                ->send();

            return;
        }

        if ($this->selectedAccount->frozen) {
            Notification::make()
                ->title('Account Frozen')
                ->body('Cannot fund a frozen account. Please unfreeze first.')
                ->danger()
                ->send();

            return;
        }

        try {
            $this->requestFundingApproval($data);

            Notification::make()
                ->title('Funding Request Submitted')
                ->body('The funding request has been submitted for approval.')
                ->success()
                ->send();

            $this->reset(['accountUuid', 'selectedAccount']);

        } catch (Throwable $e) {
            Notification::make()
                ->title('Funding Request Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function authorizeWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());
    }
}
