<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing;

use App\Domain\Segments\Enums\SegmentSource;
use App\Domain\Segments\Models\CustomerSegment;
use App\Domain\Segments\Services\SegmentEvaluator;
use App\Filament\Admin\Resources\Pricing\CustomerSegmentResource\Pages;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use App\Models\User;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CustomerSegmentResource extends Resource
{
    use RespectsModuleVisibility;

    protected static ?string $model = CustomerSegment::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Pricing & Revenue';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Segments';

    protected static ?string $modelLabel = 'Customer segment';

    protected static ?string $pluralModelLabel = 'Customer segments';

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
        $sourceOptions = collect(SegmentSource::cases())
            ->mapWithKeys(fn (SegmentSource $s): array => [$s->value => ucfirst($s->value)])
            ->all();

        return $form
            ->schema([
                Forms\Components\Section::make('Segment identity')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->helperText('Machine-readable identifier, e.g. high_value_users'),

                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(150),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('source')
                            ->label('Source')
                            ->options($sourceOptions)
                            ->required()
                            ->helperText('Static = manual membership; Dynamic = rule-based; Hybrid = both'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Rules DSL')
                    ->schema([
                        Forms\Components\Textarea::make('rules')
                            ->label('Segment rules (JSON)')
                            ->rows(10)
                            ->columnSpanFull()
                            ->helperText(
                                'JSON object using all/any structure. Example:' . PHP_EOL .
                                '{' . PHP_EOL .
                                '  "all": [' . PHP_EOL .
                                '    {"field": "user.kyc_tier", "op": "gte", "value": 2},' . PHP_EOL .
                                '    {"any": [' . PHP_EOL .
                                '      {"field": "account.type", "op": "eq", "value": "personal"},' . PHP_EOL .
                                '      {"field": "team.is_business", "op": "eq", "value": true}' . PHP_EOL .
                                '    ]}' . PHP_EOL .
                                '  ]' . PHP_EOL .
                                '}'
                            )
                            ->dehydrateStateUsing(fn (?string $state): ?array => $state !== null && $state !== '' ? json_decode($state, true) : null)
                            ->formatStateUsing(fn (mixed $state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT) : (string) ($state ?? '')),
                    ]),

                Forms\Components\Section::make('Availability')
                    ->schema([
                        Forms\Components\Toggle::make('active')
                            ->label('Active')
                            ->default(true),

                        Forms\Components\DateTimePicker::make('effective_from')
                            ->label('Effective from')
                            ->before('effective_to'),

                        Forms\Components\DateTimePicker::make('effective_to')
                            ->label('Effective to')
                            ->after('effective_from'),
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

                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color(
                        fn (SegmentSource $state): string => match ($state) {
                            SegmentSource::Static  => 'gray',
                            SegmentSource::Dynamic => 'info',
                            SegmentSource::Hybrid  => 'warning',
                        }
                    )
                    ->formatStateUsing(fn (SegmentSource $state): string => ucfirst($state->value))
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
                Tables\Filters\SelectFilter::make('source')
                    ->options(
                        collect(SegmentSource::cases())
                            ->mapWithKeys(fn (SegmentSource $s): array => [$s->value => ucfirst($s->value)])
                            ->all()
                    ),

                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active status'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('preview_membership')
                    ->label('Preview count')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->visible(fn (): bool => static::canViewAny())
                    ->action(function (CustomerSegment $record): void {
                        $evaluator = app(SegmentEvaluator::class);

                        $count = User::query()
                            ->get(['id'])
                            ->filter(fn (User $user): bool => in_array($record->id, $evaluator->userSegmentIds($user->id), true))
                            ->count();

                        Notification::make()
                            ->title("Matches {$count} user(s)")
                            ->body("Segment \"{$record->name}\" currently matches {$count} user(s) based on active rules.")
                            ->info()
                            ->send();
                    }),

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
            'index'  => Pages\ListCustomerSegments::route('/'),
            'create' => Pages\CreateCustomerSegment::route('/create'),
            'edit'   => Pages\EditCustomerSegment::route('/{record}/edit'),
        ];
    }
}
