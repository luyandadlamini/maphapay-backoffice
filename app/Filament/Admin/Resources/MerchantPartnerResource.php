<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\MerchantPartnerResource\Pages;
use App\Filament\Admin\Resources\MerchantPartnerResource\RelationManagers;
use App\Models\MerchantPartner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Table;

class MerchantPartnerResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = MerchantPartner::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Merchants & Orgs';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'QR Partners';

    public static function form(Form $form): Form
    {
        return $form
            ->schema(
                [
                    Forms\Components\Section::make('Partner Details')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('name')
                                    ->label('Partner Name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('category')
                                    ->label('Category')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('logo_url')
                                    ->label('Logo URL')
                                    ->url()
                                    ->maxLength(500),
                            ]
                        )->columns(3),

                    Forms\Components\Section::make('QR Integration')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('qr_endpoint')
                                    ->label('QR Endpoint')
                                    ->url()
                                    ->maxLength(500),
                                Forms\Components\TextInput::make('api_key')
                                    ->label('API Key')
                                    ->password()
                                    ->maxLength(255),
                            ]
                        )->columns(2),

                    Forms\Components\Section::make('Commission')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('commission_rate')
                                    ->label('Commission Rate')
                                    ->numeric()
                                    ->suffix('%')
                                    ->step(0.01),
                                Forms\Components\TextInput::make('payout_schedule')
                                    ->label('Payout Schedule')
                                    ->maxLength(100),
                            ]
                        )->columns(2),

                    Forms\Components\Section::make('Minor Eligibility')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('bonus_multiplier')
                                    ->label('Bonus Multiplier')
                                    ->numeric()
                                    ->default(2.0)
                                    ->minValue(1.0)
                                    ->maxValue(5.0)
                                    ->helperText('Points multiplier (default 2.0, max 5.0)'),
                                Forms\Components\TextInput::make('min_age_allowance')
                                    ->label('Minimum Age Allowance')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(18)
                                    ->helperText('Minimum age for bonus eligibility'),
                                Forms\Components\CheckboxList::make('category_slugs')
                                    ->label('Eligible Categories')
                                    ->options([
                                        'grocery'       => 'Grocery',
                                        'airtime'       => 'Airtime',
                                        'retail'        => 'Retail',
                                        'food_beverage' => 'Food & Beverage',
                                    ]),
                                Forms\Components\Toggle::make('is_active_for_minors')
                                    ->label('Active for Minors')
                                    ->default(true),
                                Forms\Components\Textarea::make('bonus_terms')
                                    ->label('Bonus Terms')
                                    ->helperText('Terms displayed to minor users')
                                    ->rows(3),
                            ]
                        )->columns(2),

                    Forms\Components\Section::make('Status')
                        ->schema(
                            [
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true),
                            ]
                        ),
                ]
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('name')
                        ->label('Partner')
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('category')
                        ->label('Category')
                        ->badge()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('bonus_multiplier')
                        ->label('Bonus')
                        ->numeric(2)
                        ->sortable(),
                    Tables\Columns\TextColumn::make('min_age_allowance')
                        ->label('Min Age')
                        ->sortable(),
                    Tables\Columns\IconColumn::make('is_active_for_minors')
                        ->label('Minors')
                        ->boolean()
                        ->sortable(),
                    Tables\Columns\IconColumn::make('is_active')
                        ->label('Active')
                        ->boolean()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('commission_rate')
                        ->label('Commission')
                        ->suffix('%')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Created')
                        ->dateTime()
                        ->sortable()
                        ->toggleable(),
                ]
            )
            ->defaultSort('name', 'asc')
            ->filters(
                [
                    Tables\Filters\Filter::make('is_active_for_minors')
                        ->query(fn ($query) => $query->where('is_active_for_minors', true))
                        ->label('Minor-eligible only'),
                    Tables\Filters\SelectFilter::make('is_active')
                        ->options([
                            true  => 'Active',
                            false => 'Inactive',
                        ]),
                    Tables\Filters\SelectFilter::make('category')
                        ->options([
                            'grocery'       => 'Grocery',
                            'airtime'       => 'Airtime',
                            'retail'        => 'Retail',
                            'food_beverage' => 'Food & Beverage',
                        ]),
                ]
            )
            ->actions(
                [
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                ]
            )
            ->bulkActions(
                [
                    BulkActionGroup::make(
                        [
                            BulkAction::make('toggle_minor_eligibility')
                                ->label('Toggle Minor Eligibility')
                                ->action(function (array $records) {
                                    foreach ($records as $record) {
                                        $record->update(['is_active_for_minors' => ! $record->is_active_for_minors]);
                                    }
                                }),
                        ]
                    ),
                ]
            );
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MinorBonusTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMerchantPartners::route('/'),
            'view'  => Pages\ViewMerchantPartner::route('/{record}'),
            'edit'  => Pages\EditMerchantPartner::route('/{record}/edit'),
        ];
    }
}
