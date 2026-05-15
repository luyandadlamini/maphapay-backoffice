<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Services\MoneySettlerService;
use App\Filament\Admin\Resources\WalletProviderTransactionResource\Pages;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WalletProviderTransactionResource extends Resource
{
    protected static ?string $model = WalletProviderTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Wallet Provider Transaction';

    protected static ?string $pluralModelLabel = 'Wallet Provider Transactions';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', WalletProviderTransaction::STATUS_FAILED)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::where('status', WalletProviderTransaction::STATUS_FAILED)->count() > 0
            ? 'danger'
            : 'primary';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-transactions') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('provider_id')->disabled(),
            TextInput::make('provider_request_id')->disabled(),
            TextInput::make('type')->disabled(),
            TextInput::make('status')->disabled(),
            TextInput::make('currency')->disabled(),
            TextInput::make('amount_minor')->disabled(),
            TextInput::make('user_uuid')->disabled(),
            TextInput::make('settled_at')->disabled(),
            KeyValue::make('payload')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider_id')
                    ->label('Provider')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('provider_request_id')
                    ->label('Reference')
                    ->searchable()
                    ->copyable()
                    ->limit(20),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WalletProviderTransaction::TYPE_COLLECT  => 'success',
                        WalletProviderTransaction::TYPE_DISBURSE => 'warning',
                        default                                  => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WalletProviderTransaction::STATUS_SUCCESSFUL => 'success',
                        WalletProviderTransaction::STATUS_FAILED     => 'danger',
                        WalletProviderTransaction::STATUS_PENDING    => 'warning',
                        default                                      => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('currency')->label('Ccy'),
                TextColumn::make('amount_minor')
                    ->label('Amount (minor)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('user_uuid')
                    ->label('User')
                    ->limit(8)
                    ->searchable(),
                TextColumn::make('settled_at')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider_id')
                    ->options([
                        'mtn_momo'              => 'MTN MoMo',
                        'emali_eswatini_mobile' => 'eMali',
                        'fnb_ewallet'           => 'FNB eWallet',
                        'standard_unayo'        => 'Standard Unayo',
                        'nedbank_send_money'    => 'Nedbank Send Money',
                    ]),
                SelectFilter::make('type')
                    ->options([
                        WalletProviderTransaction::TYPE_COLLECT  => 'Collect',
                        WalletProviderTransaction::TYPE_DISBURSE => 'Disburse',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        WalletProviderTransaction::STATUS_PENDING    => 'Pending',
                        WalletProviderTransaction::STATUS_SUCCESSFUL => 'Successful',
                        WalletProviderTransaction::STATUS_FAILED     => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('replay_callback')
                    ->label('Replay callback')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (WalletProviderTransaction $row): bool => $row->status === WalletProviderTransaction::STATUS_PENDING)
                    ->action(function (WalletProviderTransaction $row): void {
                        /** @var MoneySettlerService $settler */
                        $settler = app(MoneySettlerService::class);

                        // Best-effort replay using payload remote_status if recorded, else assume SUCCESSFUL.
                        $payload = (array) $row->payload;
                        $remote = isset($payload['remote_status']) && is_scalar($payload['remote_status'])
                            ? (string) $payload['remote_status']
                            : 'SUCCESSFUL';

                        $settler->settle($row->provider_id, $row->provider_request_id, $remote, $payload);

                        Notification::make()
                            ->title('Callback replayed')
                            ->body("Settler invoked for {$row->provider_id} / {$row->provider_request_id}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletProviderTransactions::route('/'),
            'view'  => Pages\ViewWalletProviderTransaction::route('/{record}'),
        ];
    }
}
