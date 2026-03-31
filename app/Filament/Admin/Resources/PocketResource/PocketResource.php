<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PocketResource;

use App\Domain\Mobile\Models\Pocket;
use App\Filament\Admin\Resources\PocketResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PocketResource extends Resource
{
    protected static ?string $model = Pocket::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Mobile Features';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Pocket Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Pocket Name')
                            ->required()
                            ->maxLength(100),

                        Forms\Components\Select::make('user_uuid')
                            ->label('User')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->required(),

                        Forms\Components\Select::make('category')
                            ->label('Category')
                            ->options(array_combine(Pocket::CATEGORIES, array_map('ucfirst', Pocket::CATEGORIES))),

                        Forms\Components\ColorPicker::make('color')
                            ->label('Color')
                            ->default('#4F8CFF'),
                    ])->columns(2),

                Forms\Components\Section::make('Financials')
                    ->schema([
                        Forms\Components\TextInput::make('target_amount')
                            ->label('Target Amount')
                            ->numeric()
                            ->required(),

                        Forms\Components\TextInput::make('current_amount')
                            ->label('Current Amount')
                            ->numeric()
                            ->readonly(),

                        Forms\Components\DatePicker::make('target_date')
                            ->label('Target Date'),
                    ])->columns(3),

                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\Toggle::make('is_completed')
                            ->label('Completed'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge(),

                Tables\Columns\TextColumn::make('current_amount')
                    ->label('Saved')
                    ->money('USD')
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_amount')
                    ->label('Target')
                    ->money('USD')
                    ->sortable(),

                Tables\Columns\TextColumn::make('progress_percentage')
                    ->label('Progress')
                    ->suffix('%')
                    ->getStateUsing(fn (Pocket $record): string => number_format($record->progress_percentage, 1)),

                Tables\Columns\TextColumn::make('target_date')
                    ->label('Target Date')
                    ->date(),

                Tables\Columns\IconColumn::make('is_completed')
                    ->label('Completed')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(array_combine(Pocket::CATEGORIES, array_map('ucfirst', Pocket::CATEGORIES))),

                Tables\Filters\Filter::make('is_completed')
                    ->label('Completed')
                    ->query(fn ($query) => $query->where('is_completed', true)),

                Tables\Filters\Filter::make('not_completed')
                    ->label('Not Completed')
                    ->query(fn ($query) => $query->where('is_completed', false)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPockets::route('/'),
            'view' => Pages\ViewPocket::route('/{record}'),
            'edit' => Pages\EditPocket::route('/{record}/edit'),
        ];
    }
}