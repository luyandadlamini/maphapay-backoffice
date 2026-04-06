<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RewardProfileResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class QuestCompletionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questCompletions';

    protected static ?string $title = 'Quest Completions';

    public function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('quest.title')
                        ->label('Quest')
                        ->searchable(),
                    Tables\Columns\TextColumn::make('quest.category')
                        ->label('Category')
                        ->badge(),
                    Tables\Columns\TextColumn::make('xp_earned')
                        ->label('XP Earned')
                        ->numeric(),
                    Tables\Columns\TextColumn::make('points_earned')
                        ->label('Points Earned')
                        ->numeric(),
                    Tables\Columns\TextColumn::make('completed_at')
                        ->label('Completed')
                        ->dateTime('M j, Y g:i A')
                        ->sortable(),
                ]
            )
            ->defaultSort('completed_at', 'desc')
            ->actions(
                [
                    Tables\Actions\ViewAction::make(),
                ]
            )
            ->bulkActions([]);
    }
}
