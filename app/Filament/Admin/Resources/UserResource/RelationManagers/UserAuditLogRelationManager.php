<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class UserAuditLogRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Audit Trail';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->columns([
                Tables\Columns\TextColumn::make('action')->badge(),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Entity')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->limit(40),
                Tables\Columns\TextColumn::make('tags')->label('Tags')->limit(30),
                Tables\Columns\TextColumn::make('ip_address')->label('IP Address'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
