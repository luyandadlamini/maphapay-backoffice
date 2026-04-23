<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MinorAccountLifecycleExceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'lifecycleExceptions';

    protected static ?string $title = 'Lifecycle exceptions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reason_code')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('source')->badge(),
                Tables\Columns\TextColumn::make('last_seen_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('sla_due_at')->dateTime()->sortable(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
