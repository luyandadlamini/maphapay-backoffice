<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Filament\Admin\Resources\MinorFamilySupportTransferResource\Pages;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MinorFamilySupportTransferResource extends Resource
{
    protected static ?string $model = MinorFamilySupportTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $modelLabel = 'Minor Family Support Transfer';

    protected static ?string $pluralModelLabel = 'Minor Family Support Transfers';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-transactions') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('id')->label('UUID')->disabled(),
            TextInput::make('minor_account_uuid')->disabled(),
            TextInput::make('actor_user_uuid')->disabled(),
            TextInput::make('source_account_uuid')->disabled(),
            TextInput::make('status')->disabled(),
            TextInput::make('provider_name')->disabled(),
            TextInput::make('provider_reference_id')->disabled(),
            TextInput::make('recipient_name')->disabled(),
            TextInput::make('recipient_msisdn')->disabled(),
            TextInput::make('amount')->disabled(),
            TextInput::make('asset_code')->disabled(),
            TextInput::make('wallet_refunded_at')->disabled(),
            TextInput::make('failed_reason')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Transfer')
                    ->copyable()
                    ->searchable()
                    ->limit(12),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('recipient_name')
                    ->searchable(),
                TextColumn::make('recipient_msisdn')
                    ->searchable(),
                TextColumn::make('amount')
                    ->money(fn (MinorFamilySupportTransfer $record): string => $record->asset_code),
                TextColumn::make('provider_name')
                    ->badge(),
                TextColumn::make('provider_reference_id')
                    ->label('Provider Reference')
                    ->toggleable(),
                TextColumn::make('wallet_refunded_at')
                    ->label('Refunded At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('failed_reason')
                    ->label('Reconciliation Context')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER => 'Pending Provider',
                        MinorFamilySupportTransfer::STATUS_SUCCESSFUL => 'Successful',
                        MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED => 'Failed Refunded',
                        MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED => 'Failed Unreconciled',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMinorFamilySupportTransfers::route('/'),
            'view' => Pages\ViewMinorFamilySupportTransfer::route('/{record}'),
        ];
    }
}
