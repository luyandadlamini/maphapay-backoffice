<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing;

use App\Domain\Pricing\Enums\PricingCategory;
use App\Domain\Pricing\Models\PricingProduct;
use App\Filament\Admin\Resources\Pricing\PricingProductResource\Pages;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PricingProductResource extends Resource
{
    use RespectsModuleVisibility;

    protected static ?string $model = PricingProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Pricing & Revenue';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $modelLabel = 'Pricing product';

    protected static ?string $pluralModelLabel = 'Pricing products';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $access = app(BackofficeWorkspaceAccess::class);

        return $access->canAccess('finance', $user) || $access->canAccess('platform_administration', $user);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        $categoryOptions = collect(PricingCategory::cases())
            ->mapWithKeys(fn (PricingCategory $c): array => [$c->value => $c->label()])
            ->all();

        return $form
            ->schema([
                Forms\Components\Section::make('Product details')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique machine-readable identifier, e.g. send_money_local'),

                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(150),

                        Forms\Components\Select::make('category')
                            ->label('Category')
                            ->options($categoryOptions)
                            ->required()
                            ->searchable(),

                        Forms\Components\TextInput::make('default_currency')
                            ->label('Default currency')
                            ->required()
                            ->length(3)
                            ->default('SZL')
                            ->maxLength(10)
                            ->helperText('ISO 4217 currency code, e.g. SZL'),

                        Forms\Components\TextInput::make('elasticity_bps_per_pct')
                            ->label('Elasticity (bps per %)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Basis points fee shift per 1 % volume change'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Availability')
                    ->schema([
                        Forms\Components\Toggle::make('active')
                            ->label('Active')
                            ->default(true),

                        Forms\Components\DateTimePicker::make('effective_from')
                            ->label('Effective from')
                            ->before('effective_to')
                            ->helperText('Leave blank to be effective immediately'),

                        Forms\Components\DateTimePicker::make('effective_to')
                            ->label('Effective to')
                            ->after('effective_from')
                            ->helperText('Leave blank for no expiry'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (PricingCategory $state): string => $state->label())
                    ->sortable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('From')
                    ->dateTime()
                    ->placeholder('Immediate')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('effective_to')
                    ->label('To')
                    ->dateTime()
                    ->placeholder('No expiry')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(
                        collect(PricingCategory::cases())
                            ->mapWithKeys(fn (PricingCategory $c): array => [$c->value => $c->label()])
                            ->all()
                    ),

                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active status'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPricingProducts::route('/'),
            'create' => Pages\CreatePricingProduct::route('/create'),
            'edit'   => Pages\EditPricingProduct::route('/{record}/edit'),
        ];
    }
}
