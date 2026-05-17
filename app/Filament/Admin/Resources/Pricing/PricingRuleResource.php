<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing;

use App\Domain\Pricing\Enums\FeeFormula;
use App\Domain\Pricing\Enums\PricingRuleStatus;
use App\Domain\Pricing\Models\PricingRule;
use App\Filament\Admin\Resources\Pricing\PricingRuleResource\Pages;
use App\Filament\Admin\Resources\Pricing\PricingRuleResource\RelationManagers;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PricingRuleResource extends Resource
{
    use RespectsModuleVisibility;

    protected static ?string $model = PricingRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Pricing & Revenue';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Rules';

    protected static ?string $modelLabel = 'Pricing rule';

    protected static ?string $pluralModelLabel = 'Pricing rules';

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
        return $form
            ->schema([
                Forms\Components\Section::make('Rule configuration')
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label('Product')
                            ->relationship('product', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('segment_id')
                            ->label('Segment (optional)')
                            ->relationship('segment', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('No segment — applies to all users'),

                        Forms\Components\Select::make('formula')
                            ->label('Formula')
                            ->options(
                                collect(FeeFormula::cases())
                                    ->mapWithKeys(fn (FeeFormula $f): array => [$f->value => ucfirst(str_replace('_', ' ', $f->value))])
                                    ->all()
                            )
                            ->required()
                            ->reactive()
                            ->helperText('Determines which config fields appear below'),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(
                                collect(PricingRuleStatus::cases())
                                    ->mapWithKeys(fn (PricingRuleStatus $s): array => [$s->value => ucfirst(str_replace('_', ' ', $s->value))])
                                    ->all()
                            )
                            ->default(PricingRuleStatus::Draft->value)
                            ->required()
                            ->helperText('Setting to "active" submits an approval request; record stays at pending_approval until approved'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Fixed fee')
                    ->schema([
                        Forms\Components\TextInput::make('config.flat_fee')
                            ->label('Flat fee (minor units)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText('e.g. 150 = 1.50 SZL'),
                    ])
                    ->visible(fn (Get $get): bool => $get('formula') === FeeFormula::Fixed->value),

                Forms\Components\Section::make('Percentage fee')
                    ->schema([
                        Forms\Components\TextInput::make('config.rate_bps')
                            ->label('Rate (basis points)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText('100 bps = 1 %'),

                        Forms\Components\TextInput::make('config.min_fee')
                            ->label('Minimum fee (minor units)')
                            ->numeric()
                            ->minValue(0),

                        Forms\Components\TextInput::make('config.max_fee')
                            ->label('Maximum fee (minor units)')
                            ->numeric()
                            ->minValue(0),
                    ])
                    ->visible(fn (Get $get): bool => $get('formula') === FeeFormula::Percentage->value)
                    ->columns(3),

                Forms\Components\Section::make('Hybrid fee')
                    ->schema([
                        Forms\Components\TextInput::make('config.flat_fee')
                            ->label('Flat fee (minor units)')
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        Forms\Components\TextInput::make('config.rate_bps')
                            ->label('Rate (basis points)')
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        Forms\Components\TextInput::make('config.min_fee')
                            ->label('Minimum fee (minor units)')
                            ->numeric()
                            ->minValue(0),

                        Forms\Components\TextInput::make('config.max_fee')
                            ->label('Maximum fee (minor units)')
                            ->numeric()
                            ->minValue(0),
                    ])
                    ->visible(fn (Get $get): bool => $get('formula') === FeeFormula::Hybrid->value)
                    ->columns(2),

                Forms\Components\Section::make('Tiered / Volume bands')
                    ->schema([
                        Forms\Components\Repeater::make('config.bands')
                            ->label('Bands')
                            ->schema([
                                Forms\Components\TextInput::make('from')
                                    ->label('From (minor units)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),

                                Forms\Components\TextInput::make('to')
                                    ->label('To (minor units, blank = ∞)')
                                    ->numeric()
                                    ->minValue(0),

                                Forms\Components\TextInput::make('flat_fee')
                                    ->label('Flat fee')
                                    ->numeric()
                                    ->minValue(0),

                                Forms\Components\TextInput::make('rate_bps')
                                    ->label('Rate (bps)')
                                    ->numeric()
                                    ->minValue(0),
                            ])
                            ->columns(4)
                            ->addActionLabel('Add band')
                            ->minItems(1)
                            ->helperText('Each band defines a range with its own flat fee and/or rate'),
                    ])
                    ->visible(fn (Get $get): bool => in_array($get('formula'), [FeeFormula::Tiered->value, FeeFormula::Volume->value], true)),

                Forms\Components\Section::make('Time-window fee')
                    ->schema([
                        Forms\Components\Repeater::make('config.windows')
                            ->label('Windows')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label('Label')
                                    ->required()
                                    ->maxLength(50),

                                Forms\Components\TextInput::make('from_hour')
                                    ->label('From hour (0–23)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(23)
                                    ->required(),

                                Forms\Components\TextInput::make('to_hour')
                                    ->label('To hour (0–23)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(23)
                                    ->required(),

                                Forms\Components\TextInput::make('flat_fee')
                                    ->label('Flat fee')
                                    ->numeric()
                                    ->minValue(0),

                                Forms\Components\TextInput::make('rate_bps')
                                    ->label('Rate (bps)')
                                    ->numeric()
                                    ->minValue(0),
                            ])
                            ->columns(5)
                            ->addActionLabel('Add window'),
                    ])
                    ->visible(fn (Get $get): bool => $get('formula') === FeeFormula::TimeWindow->value),

                Forms\Components\Section::make('Scheduling & scope')
                    ->schema([
                        Forms\Components\DateTimePicker::make('effective_from')
                            ->label('Effective from')
                            ->before('effective_to'),

                        Forms\Components\DateTimePicker::make('effective_to')
                            ->label('Effective to')
                            ->after('effective_from'),

                        Forms\Components\KeyValue::make('geo_scope')
                            ->label('Geo scope')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->helperText('e.g. country_code = SZ')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('formula')
                    ->label('Formula')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (FeeFormula $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(
                        fn (PricingRuleStatus $state): string => match ($state) {
                            PricingRuleStatus::Draft           => 'gray',
                            PricingRuleStatus::PendingApproval => 'warning',
                            PricingRuleStatus::Scheduled       => 'info',
                            PricingRuleStatus::Active          => 'success',
                            PricingRuleStatus::Superseded      => 'danger',
                            PricingRuleStatus::RolledBack      => 'danger',
                        }
                    )
                    ->formatStateUsing(fn (PricingRuleStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('segment.name')
                    ->label('Segment')
                    ->placeholder('All users')
                    ->sortable(),

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
                Tables\Filters\SelectFilter::make('formula')
                    ->options(
                        collect(FeeFormula::cases())
                            ->mapWithKeys(fn (FeeFormula $f): array => [$f->value => ucfirst(str_replace('_', ' ', $f->value))])
                            ->all()
                    ),

                Tables\Filters\SelectFilter::make('status')
                    ->options(
                        collect(PricingRuleStatus::cases())
                            ->mapWithKeys(fn (PricingRuleStatus $s): array => [$s->value => ucfirst(str_replace('_', ' ', $s->value))])
                            ->all()
                    ),

                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
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
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VersionsRelationManager::class,
            RelationManagers\ProductRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPricingRules::route('/'),
            'create' => Pages\CreatePricingRule::route('/create'),
            'edit'   => Pages\EditPricingRule::route('/{record}/edit'),
        ];
    }
}
