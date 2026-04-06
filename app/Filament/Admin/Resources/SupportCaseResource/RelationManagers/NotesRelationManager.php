<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SupportCaseResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Case Notes';

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return true;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('body')
                    ->required()
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\Select::make('visibility')
                    ->options([
                        'internal'        => 'Internal Note (Staff Only)',
                        'customer-facing' => 'Customer Facing (Visible in App)',
                    ])
                    ->default('internal')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                Tables\Columns\TextColumn::make('author.name')
                    ->label('Author')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('visibility')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'internal'        => 'gray',
                        'customer-facing' => 'warning',
                        default           => 'primary',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('body')
                    ->wrap(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->hidden(false)
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['author_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn ($record) => $record->author_id === auth()->id() || auth()->user()->hasRole('super-admin')),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => $record->author_id === auth()->id() || auth()->user()->hasRole('super-admin')),
            ])
            ->bulkActions([]);
    }
}
