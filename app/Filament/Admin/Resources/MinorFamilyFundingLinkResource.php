<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Filament\Admin\Resources\MinorFamilyFundingLinkResource\Pages;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MinorFamilyFundingLinkResource extends Resource
{
    protected static ?string $model = MinorFamilyFundingLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $modelLabel = 'Minor Family Funding Link';

    protected static ?string $pluralModelLabel = 'Minor Family Funding Links';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-transactions') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('id')->label('UUID')->disabled(),
            TextInput::make('minor_account_uuid')->disabled(),
            TextInput::make('created_by_user_uuid')->disabled(),
            TextInput::make('created_by_account_uuid')->disabled(),
            TextInput::make('title')->disabled(),
            TextInput::make('status')->disabled(),
            TextInput::make('amount_mode')->disabled(),
            TextInput::make('fixed_amount')->disabled(),
            TextInput::make('target_amount')->disabled(),
            TextInput::make('collected_amount')->disabled(),
            TextInput::make('asset_code')->disabled(),
            TextInput::make('expires_at')->disabled(),
            TextInput::make('last_funded_at')->disabled(),
            KeyValue::make('provider_options')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('minor_account_uuid')
                    ->label('Minor Account')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('amount_mode')
                    ->badge(),
                TextColumn::make('collected_amount')
                    ->money(fn (MinorFamilyFundingLink $record): string => $record->asset_code),
                TextColumn::make('target_amount')
                    ->money(fn (MinorFamilyFundingLink $record): string => $record->asset_code),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MinorFamilyFundingLink::STATUS_DRAFT => 'Draft',
                        MinorFamilyFundingLink::STATUS_ACTIVE => 'Active',
                        MinorFamilyFundingLink::STATUS_PAUSED => 'Paused',
                        MinorFamilyFundingLink::STATUS_EXPIRED => 'Expired',
                        MinorFamilyFundingLink::STATUS_COMPLETED => 'Completed',
                        MinorFamilyFundingLink::STATUS_CANCELLED => 'Cancelled',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMinorFamilyFundingLinks::route('/'),
            'view' => Pages\ViewMinorFamilyFundingLink::route('/{record}'),
        ];
    }
}
