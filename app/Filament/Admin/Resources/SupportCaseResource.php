<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Support\Models\SupportCase;
use App\Filament\Admin\Resources\SupportCaseResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupportCaseResource extends Resource
{
    protected static ?string $model = SupportCase::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationGroup = 'Support Hub';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'open')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::where('status', 'open')->count() > 0 ? 'danger' : 'primary';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('user_id')
                    ->label('Customer')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->required(),
                Select::make('transaction_id')
                    ->label('Transaction (Optional)')
                    ->relationship('transaction', 'trx')
                    ->searchable()
                    ->nullable(),
                TextInput::make('subject')
                    ->required()
                    ->maxLength(255),
                Select::make('priority')
                    ->options([
                        'low'    => 'Low',
                        'medium' => 'Medium',
                        'high'   => 'High',
                        'urgent' => 'Urgent',
                    ])
                    ->required()
                    ->default('medium'),
                Select::make('status')
                    ->options([
                        'open'        => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved'    => 'Resolved',
                        'closed'      => 'Closed',
                    ])
                    ->required()
                    ->default('open'),
                Select::make('assigned_to')
                    ->label('Assign To')
                    ->relationship('assignee', 'name')
                    ->searchable()
                    ->nullable(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'low'    => 'info',
                        'medium' => 'warning',
                        'high'   => 'danger',
                        'urgent' => 'danger',
                        default  => 'secondary',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'open'        => 'danger',
                        'in_progress' => 'warning',
                        'resolved'    => 'success',
                        'closed'      => 'secondary',
                        default       => 'secondary',
                    })
                    ->sortable(),
                TextColumn::make('assignee.name')
                    ->label('Assigned To')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open'        => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved'    => 'Resolved',
                        'closed'      => 'Closed',
                    ]),
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'low'    => 'Low',
                        'medium' => 'Medium',
                        'high'   => 'High',
                        'urgent' => 'Urgent',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index'  => Pages\ListSupportCases::route('/'),
            'create' => Pages\CreateSupportCase::route('/create'),
            'view'   => Pages\ViewSupportCase::route('/{record}'),
            'edit'   => Pages\EditSupportCase::route('/{record}/edit'),
        ];
    }
}
