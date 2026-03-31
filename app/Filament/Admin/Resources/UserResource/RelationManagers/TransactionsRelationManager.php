<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $recordTitleAttribute = 'uuid';

    protected static ?string $title = 'Transaction History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('uuid')
                    ->label('Transaction ID')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(
                        fn (string $state): string => match ($state) {
                            'deposit'      => 'success',
                            'withdrawal'   => 'warning',
                            'transfer_in'  => 'info',
                            'transfer_out' => 'danger',
                            default        => 'gray',
                        }
                    ),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money('USD', 100)
                    ->color(fn ($record): string => in_array($record->type, ['deposit', 'transfer_in']) ? 'success' : 'danger')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Balance After')
                    ->money('USD', 100),
                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('hash')
                    ->label('Hash')
                    ->limit(20)
                    ->tooltip(fn ($record): string => $record->hash ?? '')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & Time')
                    ->dateTime('M j, Y g:i:s A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'deposit'      => 'Deposit',
                        'withdrawal'   => 'Withdrawal',
                        'transfer_in'  => 'Transfer In',
                        'transfer_out' => 'Transfer Out',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
