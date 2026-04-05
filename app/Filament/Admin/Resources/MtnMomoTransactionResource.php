<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\MtnMomoTransactionResource\Pages;
use App\Models\MtnMomoTransaction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MtnMomoTransactionResource extends Resource
{
    protected static ?string $model = MtnMomoTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $modelLabel = 'Mtn MoMo Transaction';

    protected static ?string $pluralModelLabel = 'Mtn MoMo Transactions';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', MtnMomoTransaction::STATUS_FAILED)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::where('status', MtnMomoTransaction::STATUS_FAILED)->count() > 0 ? 'danger' : 'primary';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('idempotency_key')->disabled(),
                TextInput::make('type')->disabled(),
                TextInput::make('amount')->disabled(),
                TextInput::make('currency')->disabled(),
                TextInput::make('status')->disabled(),
                TextInput::make('party_msisdn')->disabled(),
                TextInput::make('mtn_reference_id')->disabled(),
                TextInput::make('mtn_financial_transaction_id')->disabled(),
                TextInput::make('note')->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('amount')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        MtnMomoTransaction::STATUS_SUCCESSFUL => 'success',
                        MtnMomoTransaction::STATUS_PENDING    => 'warning',
                        MtnMomoTransaction::STATUS_FAILED     => 'danger',
                        default                               => 'secondary',
                    })
                    ->sortable(),
                TextColumn::make('party_msisdn')
                    ->label('Mobile Number')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MtnMomoTransaction::STATUS_SUCCESSFUL => 'Successful',
                        MtnMomoTransaction::STATUS_PENDING    => 'Pending',
                        MtnMomoTransaction::STATUS_FAILED     => 'Failed',
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMtnMomoTransactions::route('/'),
            'view'  => Pages\ViewMtnMomoTransaction::route('/{record}'),
        ];
    }
}
