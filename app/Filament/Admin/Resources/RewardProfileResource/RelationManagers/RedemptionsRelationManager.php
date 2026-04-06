<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RewardProfileResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RedemptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'redemptions';

    protected static ?string $title = 'Redemptions';

    public function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('shopItem.title')
                        ->label('Item')
                        ->searchable(),
                    Tables\Columns\TextColumn::make('shopItem.category')
                        ->label('Category')
                        ->badge(),
                    Tables\Columns\TextColumn::make('points_spent')
                        ->label('Points Spent')
                        ->numeric(),
                    Tables\Columns\TextColumn::make('status')
                        ->badge()
                        ->color(
                            fn (string $state): string => match ($state) {
                                'completed' => 'success',
                                'pending'   => 'warning',
                                'cancelled' => 'danger',
                                default     => 'gray',
                            }
                        ),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Redeemed')
                        ->dateTime('M j, Y g:i A')
                        ->sortable(),
                ]
            )
            ->defaultSort('created_at', 'desc')
            ->actions(
                [
                    Tables\Actions\ViewAction::make(),
                ]
            )
            ->bulkActions([]);
    }
}
