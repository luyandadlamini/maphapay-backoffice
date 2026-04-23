<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use App\Filament\Admin\Resources\MinorAccountLifecycleTransitionResource\Pages;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MinorAccountLifecycleTransitionResource extends Resource
{
    protected static ?string $model = MinorAccountLifecycleTransition::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $modelLabel = 'Minor Account Lifecycle Transition';

    protected static ?string $pluralModelLabel = 'Minor Account Lifecycle Transitions';

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
            TextInput::make('id')->disabled(),
            TextInput::make('minor_account_uuid')->disabled(),
            TextInput::make('transition_type')->disabled(),
            TextInput::make('state')->disabled(),
            TextInput::make('effective_at')->disabled(),
            TextInput::make('executed_at')->disabled(),
            TextInput::make('blocked_reason_code')->disabled(),
            KeyValue::make('metadata')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Transition')->copyable()->limit(12)->searchable(),
                TextColumn::make('minor_account_uuid')->label('Minor Account')->copyable()->toggleable(),
                TextColumn::make('transition_type')->badge()->searchable(),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('effective_at')->dateTime()->sortable(),
                TextColumn::make('executed_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('blocked_reason_code')->badge()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')
                    ->options([
                        MinorAccountLifecycleTransition::STATE_PENDING => 'Pending',
                        MinorAccountLifecycleTransition::STATE_COMPLETED => 'Completed',
                        MinorAccountLifecycleTransition::STATE_BLOCKED => 'Blocked',
                    ]),
                Tables\Filters\SelectFilter::make('transition_type')
                    ->options([
                        MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE => 'Tier Advance',
                        MinorAccountLifecycleTransition::TYPE_ADULT_TRANSITION_REVIEW => 'Adult Transition Review',
                        MinorAccountLifecycleTransition::TYPE_ADULT_TRANSITION_CUTOFF => 'Adult Transition Cutoff',
                        MinorAccountLifecycleTransition::TYPE_GUARDIAN_CONTINUITY => 'Guardian Continuity',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('effective_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMinorAccountLifecycleTransitions::route('/'),
            'view' => Pages\ViewMinorAccountLifecycleTransition::route('/{record}'),
        ];
    }
}
