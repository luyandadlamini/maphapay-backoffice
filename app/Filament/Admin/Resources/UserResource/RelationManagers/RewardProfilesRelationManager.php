<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use App\Domain\Rewards\Models\RewardProfile;
use Exception;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class RewardProfilesRelationManager extends RelationManager
{
    protected static string $relationship = 'rewardProfile';

    protected static ?string $recordTitleAttribute = 'user_id';

    protected static ?string $title = 'Reward Profile';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('xp')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('level')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('points_balance')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('current_streak')
                    ->numeric(),
                Forms\Components\TextInput::make('longest_streak')
                    ->numeric(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('level')
                    ->label('Level')
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('xp')
                    ->label('XP')
                    ->sortable()
                    ->numeric(),
                Tables\Columns\ViewColumn::make('xp_progress')
                    ->label('Progress')
                    ->view('filament.tables.columns.xp-progress'),
                Tables\Columns\TextColumn::make('points_balance')
                    ->label('Points')
                    ->sortable()
                    ->numeric(),
                Tables\Columns\TextColumn::make('current_streak')
                    ->label('Current Streak')
                    ->sortable()
                    ->suffix(' days'),
                Tables\Columns\TextColumn::make('longest_streak')
                    ->label('Longest Streak')
                    ->sortable()
                    ->suffix(' days'),
                Tables\Columns\TextColumn::make('last_activity_date')
                    ->label('Last Activity')
                    ->date('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')
                    ->options(
                        collect(range(1, 50))->mapWithKeys(fn ($level) => [$level => "Level {$level}"])->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('addXp')
                    ->label('Add XP')
                    ->icon('heroicon-o-plus')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('xp_amount')
                            ->label('XP Amount')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                        Forms\Components\TextInput::make('reason')
                            ->label('Reason')
                            ->maxLength(255),
                    ])
                    ->action(function (RewardProfile $record, array $data): void {
                        $this->updateProfileBalance(
                            record: $record,
                            field: 'xp',
                            amount: (int) $data['xp_amount'],
                            operation: 'add',
                            label: 'XP',
                        );
                    }),
                Tables\Actions\Action::make('deductXp')
                    ->label('Deduct XP')
                    ->icon('heroicon-o-minus')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('xp_amount')
                            ->label('XP Amount')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                        Forms\Components\TextInput::make('reason')
                            ->label('Reason')
                            ->maxLength(255),
                    ])
                    ->action(function (RewardProfile $record, array $data): void {
                        $this->updateProfileBalance(
                            record: $record,
                            field: 'xp',
                            amount: (int) $data['xp_amount'],
                            operation: 'deduct',
                            label: 'XP',
                        );
                    }),
                Tables\Actions\Action::make('addPoints')
                    ->label('Add Points')
                    ->icon('heroicon-o-plus-circle')
                    ->color('info')
                    ->form([
                        Forms\Components\TextInput::make('points_amount')
                            ->label('Points Amount')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                        Forms\Components\TextInput::make('reason')
                            ->label('Reason')
                            ->maxLength(255),
                    ])
                    ->action(function (RewardProfile $record, array $data): void {
                        $this->updateProfileBalance(
                            record: $record,
                            field: 'points_balance',
                            amount: (int) $data['points_amount'],
                            operation: 'add',
                            label: 'points',
                        );
                    }),
                Tables\Actions\Action::make('deductPoints')
                    ->label('Deduct Points')
                    ->icon('heroicon-o-minus-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\TextInput::make('points_amount')
                            ->label('Points Amount')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                        Forms\Components\TextInput::make('reason')
                            ->label('Reason')
                            ->maxLength(255),
                    ])
                    ->action(function (RewardProfile $record, array $data): void {
                        $this->updateProfileBalance(
                            record: $record,
                            field: 'points_balance',
                            amount: (int) $data['points_amount'],
                            operation: 'deduct',
                            label: 'points',
                        );
                    }),
            ])
            ->bulkActions([]);
    }

    private function updateProfileBalance(
        RewardProfile $record,
        string $field,
        int $amount,
        string $operation,
        string $label,
    ): void {
        try {
            DB::transaction(function () use ($record, $field, $amount, $operation): void {
                $current = (int) $record->{$field};
                $record->{$field} = $operation === 'add'
                    ? $current + $amount
                    : max(0, $current - $amount);
                $record->save();
            });

            $verb = $operation === 'add' ? 'Added' : 'Deducted';
            Notification::make()
                ->title("{$label} updated")
                ->success()
                ->body("{$verb} {$amount} {$label}. New total: {$record->{$field}}")
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title("Failed to update {$label}")
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }
}
