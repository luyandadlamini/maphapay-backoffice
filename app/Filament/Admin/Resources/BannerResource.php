<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\BannerResource\Pages;
use App\Models\Banner;
use Exception;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $modelLabel = 'App Banner';

    protected static ?string $pluralModelLabel = 'App Banners';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Growth & Rewards';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-banners') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Banner Content')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('subtitle')
                            ->maxLength(240),
                        TextInput::make('action_url')
                            ->label('Link URL')
                            ->url(),
                        Select::make('action_type')
                            ->options([
                                'external'  => 'External URL',
                                'deep_link' => 'Deep Link',
                                'dismiss'   => 'Dismiss only',
                            ])
                            ->default('external')
                            ->required(),
                        TextInput::make('cta_label')
                            ->label('CTA Button Label')
                            ->maxLength(30),
                    ])->columns(2),

                Section::make('Display Settings')
                    ->schema([
                        TextInput::make('position')
                            ->label('Display Order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower = shown first'),
                        Toggle::make('active')
                            ->default(false),
                        DateTimePicker::make('starts_at')
                            ->label('Show From'),
                        DateTimePicker::make('ends_at')
                            ->label('Show Until'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('action_type')
                    ->label('Type')
                    ->badge(),
                IconColumn::make('active')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('position')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('From')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ends_at')
                    ->label('Until')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('position')
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Status'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (Banner $record) => $record->active ? 'Deactivate' : 'Activate')
                    ->icon(fn (Banner $record) => $record->active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (Banner $record) => $record->active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(function (Banner $record): void {
                        try {
                            $record->update(['active' => ! $record->active]);
                            Notification::make()
                                ->title($record->active ? 'Banner activated' : 'Banner deactivated')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()->title('Failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit'   => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
