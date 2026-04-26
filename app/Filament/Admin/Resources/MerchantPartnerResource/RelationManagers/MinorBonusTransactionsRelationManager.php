<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MerchantPartnerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MinorBonusTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'minorBonusTransactions';

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $title = 'Bonus Transaction History';

    public function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('id')
                        ->label('Transaction ID')
                        ->copyable()
                        ->limit(20),
                    Tables\Columns\TextColumn::make('minor_account_uuid')
                        ->label('Minor Account')
                        ->copyable()
                        ->limit(20),
                    Tables\Columns\TextColumn::make('parent_transaction_uuid')
                        ->label('Parent Transaction')
                        ->copyable()
                        ->limit(20),
                    Tables\Columns\TextColumn::make('amount_szl')
                        ->label('Amount (SZL)')
                        ->money('SZL')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('bonus_points_awarded')
                        ->label('Bonus Points')
                        ->numeric()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('multiplier_applied')
                        ->label('Multiplier')
                        ->numeric(2),
                    Tables\Columns\TextColumn::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(
                            fn (string $state): string => match ($state) {
                                'success' => 'success',
                                'failed'  => 'danger',
                                'pending' => 'warning',
                                default   => 'gray',
                            }
                        ),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Date')
                        ->dateTime('M j, Y g:i A')
                        ->sortable(),
                ]
            )
            ->defaultSort('created_at', 'desc')
            ->filters(
                [
                    Tables\Filters\SelectFilter::make('status')
                        ->options([
                            'success' => 'Success',
                            'failed'  => 'Failed',
                            'pending' => 'Pending',
                        ]),
                ]
            )
            ->actions(
                [
                    Tables\Actions\ViewAction::make()
                        ->modalHeading('Bonus Transaction Details'),
                ]
            )
            ->bulkActions([]);
    }
}
