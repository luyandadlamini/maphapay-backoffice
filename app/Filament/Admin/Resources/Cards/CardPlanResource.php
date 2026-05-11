<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Filament\Admin\Resources\Cards\CardPlanResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CardPlanResource extends Resource
{
    protected static ?string $model = CardPlan::class;

    protected static ?string $navigationGroup = 'Cards';

    protected static ?int $navigationSort = 16;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->sortable()->searchable()->copyable(),
                Tables\Columns\TextColumn::make('name')->sortable(),
                Tables\Columns\TextColumn::make('monthly_fee')->money('SZL')->sortable(),
                Tables\Columns\TextColumn::make('eligibility')->badge(),
                Tables\Columns\IconColumn::make('atm_enabled')->boolean(),
                Tables\Columns\TextColumn::make('fx_markup_bps')->label('FX bps'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('eligibility')->options(['adult' => 'Adult', 'minor' => 'Minor']),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                    ->mutateRecordDataUsing(function (array $data): array {
                        return $data; // Data populated into the form
                    })
                    ->using(function (Model $record, array $data): Model {
                        $before = $record->toArray();

                        $record->update($data);

                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();

                        app(CardAuditService::class)->recordAdminAction(
                            admin: $admin,
                            action: 'card_plan.admin_edit',
                            entityType: CardPlan::class,
                            entityId: (string) $record->id,
                            metadata: [
                                'before' => $before,
                                'after'  => $record->toArray(),
                            ]
                        );

                        return $record;
                    }),
            ])
            ->defaultSort('monthly_fee');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('General')
                ->schema([
                    Forms\Components\TextInput::make('name')->required()->maxLength(64),
                    Forms\Components\TextInput::make('monthly_fee')->numeric()->step('0.01')->required(),
                    Forms\Components\Toggle::make('active')->default(true),
                ])->columns(2),

            Forms\Components\Section::make('Card Limits')
                ->schema([
                    Forms\Components\TextInput::make('max_virtual_cards')->numeric()->required()->minValue(0),
                    Forms\Components\TextInput::make('max_physical_cards')->numeric()->required()->minValue(0),
                    Forms\Components\TextInput::make('monthly_card_creation_limit')->numeric()->required()->minValue(0),
                    Forms\Components\TextInput::make('free_virtual_reissues_per_month')->numeric()->required()->minValue(0),
                    Forms\Components\TextInput::make('virtual_card_replacement_fee')->numeric()->step('0.01')->required()->minValue(0),
                    Forms\Components\TextInput::make('physical_card_issuance_fee')->numeric()->step('0.01')->required()->minValue(0),
                    Forms\Components\TextInput::make('physical_card_replacement_fee')->numeric()->step('0.01')->required()->minValue(0),
                ])->columns(2),

            Forms\Components\Section::make('Spend Limits')
                ->schema([
                    Forms\Components\TextInput::make('monthly_card_spend_limit')->numeric()->step('0.01')->required()->minValue(0),
                    Forms\Components\TextInput::make('daily_card_spend_limit')->numeric()->step('0.01')->required()->minValue(0),
                    Forms\Components\TextInput::make('single_transaction_limit')->numeric()->step('0.01')->required()->minValue(0),
                ])->columns(2),

            Forms\Components\Section::make('ATM & FX')
                ->schema([
                    Forms\Components\Toggle::make('atm_enabled')->default(false),
                    Forms\Components\TextInput::make('atm_daily_limit')->numeric()->step('0.01')->required()->minValue(0),
                    Forms\Components\TextInput::make('atm_monthly_limit')->numeric()->step('0.01')->required()->minValue(0),
                    Forms\Components\TextInput::make('atm_fixed_fee')->numeric()->step('0.01')->required()->minValue(0),
                    Forms\Components\TextInput::make('atm_percentage_fee_bps')->numeric()->required()->minValue(0),
                    Forms\Components\TextInput::make('fx_markup_bps')->numeric()->required()->minValue(0),
                ])->columns(2),
        ]);
    }

    public static function canCreate(): bool
    {
        return false; // Plans created via seeder only.
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCardPlans::route('/'),
            // No edit page, we use a modal action for editing
        ];
    }
}
