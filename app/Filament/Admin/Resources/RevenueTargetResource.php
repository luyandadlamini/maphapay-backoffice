<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Analytics\Models\RevenueTarget;
use App\Domain\Analytics\WalletRevenueStream;
use App\Filament\Admin\Resources\RevenueTargetResource\Pages;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Table;

class RevenueTargetResource extends Resource
{
    protected static ?string $model = RevenueTarget::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'Revenue & Performance';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Targets & forecasts';

    protected static ?string $modelLabel = 'Revenue target';

    protected static ?string $pluralModelLabel = 'Revenue targets';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $access = app(BackofficeWorkspaceAccess::class);

        return $access->canAccess('finance', $user)
            || $access->canAccess('platform_administration', $user);
    }

    public static function form(Form $form): Form
    {
        $streamOptions = collect(WalletRevenueStream::cases())
            ->mapWithKeys(fn (WalletRevenueStream $s): array => [$s->value => $s->label()])
            ->all();

        return $form
            ->schema(
                [
                    Forms\Components\TextInput::make('period_month')
                        ->label(__('Period (YYYY-MM)'))
                        ->required()
                        ->maxLength(7)
                        ->placeholder('2026-01')
                        ->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                        ->helperText(__('First day of month is implied; store as YYYY-MM.')),
                    Forms\Components\Select::make('stream_code')
                        ->label(__('Stream'))
                        ->options($streamOptions)
                        ->required()
                        ->searchable(),
                    Forms\Components\TextInput::make('amount')
                        ->label(__('Target amount'))
                        ->numeric()
                        ->required()
                        ->step(0.01)
                        ->minValue(0),
                    Forms\Components\TextInput::make('currency')
                        ->label(__('Currency (ISO 4217)'))
                        ->required()
                        ->length(3)
                        ->default('ZAR')
                        ->maxLength(3)
                        ->rules([
                            function (Forms\Get $get): Closure {
                                return function (string $attribute, string $state, Closure $fail) use ($get): void {
                                    $streamCode = $get('stream_code');
                                    if ($streamCode === null || $state === '') {
                                        return;
                                    }
                                    $stream = WalletRevenueStream::tryFrom($streamCode);
                                    if ($stream === null) {
                                        return;
                                    }
                                    if (strtoupper($state) !== $stream->defaultCurrency()) {
                                        $fail(__('The expected currency for :stream is :currency.', [
                                            'stream'   => $stream->label(),
                                            'currency' => $stream->defaultCurrency(),
                                        ]));
                                    }
                                };
                            },
                        ]),
                    Forms\Components\Textarea::make('notes')
                        ->label(__('Notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]
            )
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('period_month')
                        ->label(__('Period'))
                        ->sortable()
                        ->searchable(),
                    Tables\Columns\TextColumn::make('stream_code')
                        ->label(__('Stream code'))
                        ->sortable()
                        ->searchable(),
                    Tables\Columns\TextColumn::make('amount')
                        ->label(__('Amount'))
                        ->numeric(decimalPlaces: 2)
                        ->sortable(),
                    Tables\Columns\TextColumn::make('currency')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('updated_at')
                        ->dateTime()
                        ->sortable(),
                ]
            )
            ->filters([])
            ->actions(
                [
                    Tables\Actions\EditAction::make(),
                    RestoreAction::make(),
                ]
            )
            ->bulkActions(
                [
                    Tables\Actions\DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]
            );
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRevenueTargets::route('/'),
            'create' => Pages\CreateRevenueTarget::route('/create'),
            'edit'   => Pages\EditRevenueTarget::route('/{record}/edit'),
        ];
    }
}
