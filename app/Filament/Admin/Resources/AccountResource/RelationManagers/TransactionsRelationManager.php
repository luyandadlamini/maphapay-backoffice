<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\RelationManagers;

use App\Filament\Exports\TransactionExporter;
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
            ->columns(
                [
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
                        ->money(config('banking.default_currency', 'SZL'), 100)
                        ->color(fn ($record): string => in_array($record->type, ['deposit', 'transfer_in']) ? 'success' : 'danger')
                        ->weight('bold'),
                    Tables\Columns\TextColumn::make('balance_after')
                        ->label('Balance After')
                        ->money(config('banking.default_currency', 'SZL'), 100),
                    Tables\Columns\TextColumn::make('reference')
                        ->label('Reference')
                        ->searchable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('hash')
                        ->label('Hash')
                        ->limit(20)
                        ->tooltip(fn ($record): string => $record->hash)
                        ->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Date & Time')
                        ->dateTime('M j, Y g:i:s A')
                        ->sortable(),
                ]
            )
            ->defaultSort('created_at', 'desc')
            ->filters(
                [
                    Tables\Filters\SelectFilter::make('type')
                        ->options(
                            [
                                'deposit'      => 'Deposit',
                                'withdrawal'   => 'Withdrawal',
                                'transfer_in'  => 'Transfer In',
                                'transfer_out' => 'Transfer Out',
                            ]
                        ),
                ]
            )
            ->headerActions(
                [
                    Tables\Actions\ExportAction::make()
                        ->exporter(TransactionExporter::class)
                        ->label('Export Transactions')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('success'),
                ]
            )
            ->actions(
                [
                    Tables\Actions\ViewAction::make()
                        ->modalHeading('Transaction Details')
                        ->modalContent(fn ($record) => view('filament.admin.resources.account-resource.transaction-details', ['transaction' => $record])),
                ]
            )
            ->bulkActions([]);
    }
}
