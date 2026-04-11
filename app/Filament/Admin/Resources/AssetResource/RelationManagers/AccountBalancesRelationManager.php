<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AssetResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountBalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'accountBalances';

    protected static ?string $title = 'Account Balances';

    protected static ?string $icon = 'heroicon-m-banknotes';

    public function form(Form $form): Form
    {
        return $form
            ->schema(
                [
                    Forms\Components\Select::make('account_uuid')
                        ->label('Account')
                        ->relationship('account', 'uuid')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\TextInput::make('balance')
                        ->label('Balance')
                        ->numeric()
                        ->required()
                        ->helperText('Balance in smallest unit (e.g., cents for USD)'),
                ]
            );
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account_uuid')
            ->columns(
                [
                    Tables\Columns\TextColumn::make('account.uuid')
                        ->label('Account UUID')
                        ->searchable()
                        ->copyable()
                        ->copyMessage('Account UUID copied')
                        ->limit(20)
                        ->tooltip(fn ($record) => $record->account->uuid),

                    Tables\Columns\TextColumn::make('account.user.name')
                        ->label('Account Owner')
                        ->searchable()
                        ->placeholder('—'),

                    Tables\Columns\TextColumn::make('balance')
                        ->label('Balance')
                        ->formatStateUsing(
                            function ($state, $record) {
                                $asset = $record->asset;
                                $formatted = number_format($state / (10 ** $asset->precision), $asset->precision);

                                return "{$formatted} {$asset->code}";
                            }
                        )
                        ->sortable()
                        ->alignEnd(),

                    Tables\Columns\TextColumn::make('updated_at')
                        ->label('Last Updated')
                        ->dateTime()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ]
            )
            ->filters(
                [
                    Tables\Filters\Filter::make('positive_balance')
                        ->label('Positive Balance Only')
                        ->query(fn (Builder $query): Builder => $query->where('balance', '>', 0)),

                    Tables\Filters\Filter::make('zero_balance')
                        ->label('Zero Balance Only')
                        ->query(fn (Builder $query): Builder => $query->where('balance', '=', 0)),
                ]
            )
            ->headerActions(
                []
            )
            ->actions([])
            ->bulkActions([])
            ->defaultSort('balance', 'desc');
    }
}
