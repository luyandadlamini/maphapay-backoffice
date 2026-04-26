<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use App\Support\BankingDisplay;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CardsRelationManager extends RelationManager
{
    protected static string $relationship = 'cards';

    protected static ?string $recordTitleAttribute = 'last4';

    protected static ?string $title = 'Virtual Cards';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('last4')
                    ->label('Card')
                    ->formatStateUsing(fn (string $state): string => '**** **** **** ' . $state)
                    ->searchable(),
                Tables\Columns\TextColumn::make('issuer')
                    ->label('Issuer')
                    ->sortable(),
                Tables\Columns\TextColumn::make('network')
                    ->label('Network')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'    => 'success',
                        'frozen'    => 'warning',
                        'cancelled' => 'danger',
                        'expired'   => 'gray',
                        default     => 'gray',
                    }),
                Tables\Columns\TextColumn::make('currency')
                    ->label('Currency')
                    ->sortable(),
                Tables\Columns\TextColumn::make('spend_limit_cents')
                    ->label('Spend Limit')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? BankingDisplay::minorUnitsAsString($state) : 'No limit')
                    ->sortable(),
                Tables\Columns\TextColumn::make('spend_limit_interval')
                    ->label('Limit Interval')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('label')
                    ->label('Label')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date('M Y')
                    ->sortable(),
                Tables\Columns\IconColumn::make('frozen_at')
                    ->label('Frozen')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('danger')
                    ->falseColor('success'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Issued')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active'    => 'Active',
                        'frozen'    => 'Frozen',
                        'cancelled' => 'Cancelled',
                        'expired'   => 'Expired',
                    ]),
                Tables\Filters\SelectFilter::make('issuer')
                    ->options([
                        'marqeta' => 'Marqeta',
                        'stripe'  => 'Stripe',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
