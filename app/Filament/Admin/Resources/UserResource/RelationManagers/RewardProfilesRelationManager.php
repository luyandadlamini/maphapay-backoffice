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
                        try {
                            DB::beginTransaction();

                            $record->xp += (int) $data['xp_amount'];
                            $record->save();

                            DB::commit();

                            Notification::make()
                                ->title('XP Added')
                                ->success()
                                ->body("Added {$data['xp_amount']} XP to user. New total: {$record->xp}")
                                ->send();
                        } catch (Exception $e) {
                            DB::rollBack();

                            Notification::make()
                                ->title('Failed to Add XP')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
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
                        try {
                            DB::beginTransaction();

                            $record->xp = max(0, $record->xp - (int) $data['xp_amount']);
                            $record->save();

                            DB::commit();

                            Notification::make()
                                ->title('XP Deducted')
                                ->success()
                                ->body("Deducted {$data['xp_amount']} XP from user. New total: {$record->xp}")
                                ->send();
                        } catch (Exception $e) {
                            DB::rollBack();

                            Notification::make()
                                ->title('Failed to Deduct XP')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
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
                        try {
                            DB::beginTransaction();

                            $record->points_balance += (int) $data['points_amount'];
                            $record->save();

                            DB::commit();

                            Notification::make()
                                ->title('Points Added')
                                ->success()
                                ->body("Added {$data['points_amount']} points to user. New balance: {$record->points_balance}")
                                ->send();
                        } catch (Exception $e) {
                            DB::rollBack();

                            Notification::make()
                                ->title('Failed to Add Points')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
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
                        try {
                            DB::beginTransaction();

                            $record->points_balance = max(0, $record->points_balance - (int) $data['points_amount']);
                            $record->save();

                            DB::commit();

                            Notification::make()
                                ->title('Points Deducted')
                                ->success()
                                ->body("Deducted {$data['points_amount']} points from user. New balance: {$record->points_balance}")
                                ->send();
                        } catch (Exception $e) {
                            DB::rollBack();

                            Notification::make()
                                ->title('Failed to Deduct Points')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }
}
