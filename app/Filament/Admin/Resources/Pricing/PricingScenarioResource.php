<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing;

use App\Domain\Pricing\Models\PricingScenario;
use App\Filament\Admin\Resources\Pricing\PricingScenarioResource\Pages;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use App\Jobs\Pricing\RunPricingScenarioJob;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PricingScenarioResource extends Resource
{
    use RespectsModuleVisibility;

    protected static ?string $model = PricingScenario::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Pricing & Revenue';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Scenarios';

    protected static ?string $modelLabel = 'Pricing scenario';

    protected static ?string $pluralModelLabel = 'Pricing scenarios';

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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Scenario details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(150),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\TagsInput::make('tags')
                            ->label('Tags')
                            ->placeholder('Add tag')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Scenario details')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Name'),

                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color('info'),

                        Infolists\Components\TextEntry::make('description')
                            ->label('Description')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('tags')
                            ->label('Tags')
                            ->badge()
                            ->separator(','),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Last simulation')
                    ->schema([
                        Infolists\Components\TextEntry::make('last_run_at')
                            ->label('Last run at')
                            ->dateTime()
                            ->placeholder('Never'),

                        Infolists\Components\KeyValueEntry::make('last_run_result')
                            ->label('Result')
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->collapsible(),

                Infolists\Components\Section::make('System')
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')->dateTime(),
                    ])
                    ->columns(2)
                    ->collapsible(),
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

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(
                        fn (?string $state): string => match ($state) {
                            'active'   => 'success',
                            'draft'    => 'gray',
                            'archived' => 'danger',
                            default    => 'info',
                        }
                    )
                    ->placeholder('draft')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('run_simulation')
                    ->label('Run simulation')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => static::canViewAny())
                    ->action(function (PricingScenario $record): void {
                        dispatch(new RunPricingScenarioJob((string) $record->getKey()));

                        Notification::make()
                            ->title('Simulation queued')
                            ->body("Scenario \"{$record->name}\" has been queued. Refresh to see results.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('compare_actuals')
                    ->label('Compare with actuals')
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->visible(fn (PricingScenario $record): bool => static::canViewAny() && $record->last_run_result !== null)
                    ->modalHeading(fn (PricingScenario $record): string => "Simulation results: {$record->name}")
                    ->form(fn (PricingScenario $record): array => static::buildResultSummaryForm($record))
                    ->fillForm(fn (PricingScenario $record): array => static::fillResultSummary($record))
                    ->modalSubmitAction(false),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * @return array<Forms\Components\Component>
     */
    public static function buildResultSummaryForm(PricingScenario $scenario): array
    {
        $result = $scenario->last_run_result ?? [];

        return [
            Forms\Components\Section::make('Run metadata')
                ->schema([
                    Forms\Components\Placeholder::make('last_run_at')
                        ->label('Executed at')
                        ->content(fn (): string => $scenario->last_run_at !== null ? (string) $scenario->last_run_at : '—'),

                    Forms\Components\Placeholder::make('status')
                        ->label('Simulation status')
                        ->content(fn (): string => (string) ($result['status'] ?? '—')),
                ])
                ->columns(2),

            Forms\Components\Section::make('Result summary')
                ->schema([
                    Forms\Components\KeyValue::make('result_summary')
                        ->label('Key / Value')
                        ->disabled()
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillResultSummary(PricingScenario $scenario): array
    {
        $result = $scenario->last_run_result ?? [];

        $flat = [];
        array_walk_recursive($result, function (mixed $value, string $key) use (&$flat): void {
            $flat[$key] = (string) $value;
        });

        return ['result_summary' => $flat];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPricingScenarios::route('/'),
            'create' => Pages\CreatePricingScenario::route('/create'),
            'view'   => Pages\ViewPricingScenario::route('/{record}'),
        ];
    }
}
