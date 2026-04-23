<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MinorFamilyReconciliationExceptionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AcknowledgmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'acknowledgments';

    protected static ?string $title = 'Manual review history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('acknowledged_by_user_uuid')
                    ->label('Acknowledged by (user UUID)')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('note')
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Recorded at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
