<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use App\Domain\Account\Models\TransactionProjection;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactionProjections';

    protected static ?string $recordTitleAttribute = 'uuid';

    protected static ?string $title = 'Transaction History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('uuid')
                    ->label('Transaction ID')
                    ->copyable()
                    ->copyMessage('Transaction ID copied')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('g:i:s A')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(
                        fn (string $state): string => match ($state) {
                            'deposit', 'transfer_in' => 'success',
                            'withdrawal', 'transfer_out' => 'warning',
                            'adjustment_credit' => 'info',
                            'adjustment_debit' => 'danger',
                            default => 'gray',
                        }
                    ),
                Tables\Columns\TextColumn::make('asset_code')
                    ->label('Currency')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money(config('banking.default_currency', 'SZL'), 100)
                    ->color(fn (TransactionProjection $record): string => $record->amount > 0 ? 'success' : 'danger')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (TransactionProjection $record): string => $record->description ?? ''),
                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Reference copied')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(
                        fn (string $state): string => match ($state) {
                            'completed' => 'success',
                            'pending' => 'warning',
                            'failed' => 'danger',
                            default => 'gray',
                        }
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'deposit' => 'Deposit',
                        'withdrawal' => 'Withdrawal',
                        'transfer_in' => 'Transfer In',
                        'transfer_out' => 'Transfer Out',
                        'adjustment_credit' => 'Adjustment Credit',
                        'adjustment_debit' => 'Adjustment Debit',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'completed' => 'Completed',
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
