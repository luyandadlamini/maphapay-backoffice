<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingRuleResource\RelationManagers;

use App\Domain\Pricing\Enums\PricingCategory;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ProductRelationManager extends RelationManager
{
    protected static string $relationship = 'product';

    protected static ?string $title = 'Product';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Product details')
                    ->schema([
                        Infolists\Components\TextEntry::make('code')
                            ->label('Code')
                            ->badge()
                            ->color('primary'),

                        Infolists\Components\TextEntry::make('name')
                            ->label('Name'),

                        Infolists\Components\TextEntry::make('category')
                            ->label('Category')
                            ->badge()
                            ->color('info')
                            ->formatStateUsing(fn (PricingCategory $state): string => $state->label()),

                        Infolists\Components\TextEntry::make('default_currency')
                            ->label('Default currency'),

                        Infolists\Components\IconEntry::make('active')
                            ->label('Active')
                            ->boolean(),
                    ])
                    ->columns(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('code')->badge()->color('primary'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('category')
                    ->formatStateUsing(fn (PricingCategory $state): string => $state->label()),
                Tables\Columns\IconColumn::make('active')->boolean()->alignCenter(),
            ]);
    }
}
