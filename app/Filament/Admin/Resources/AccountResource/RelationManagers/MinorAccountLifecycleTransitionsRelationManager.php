<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MinorAccountLifecycleTransitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'lifecycleTransitions';

    protected static ?string $title = 'Lifecycle transitions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transition_type')->badge(),
                Tables\Columns\TextColumn::make('state')->badge(),
                Tables\Columns\TextColumn::make('effective_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('executed_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('blocked_reason_code')->badge(),
            ])
            ->defaultSort('effective_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
