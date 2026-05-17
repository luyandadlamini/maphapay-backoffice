<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingRuleResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Version history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->columns([
                Tables\Columns\TextColumn::make('version')
                    ->label('Version')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status_before')
                    ->label('Status before')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status_after')
                    ->label('Status after')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->placeholder('—')
                    ->limit(60),

                Tables\Columns\TextColumn::make('changed_by')
                    ->label('Changed by')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('version', 'desc')
            ->paginated([10, 25]);
    }
}
