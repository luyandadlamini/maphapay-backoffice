<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Domain\Rewards\Models\RewardProfile;
use App\Filament\Admin\Resources\UserResource;
use Exception;
use Filament\Actions;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->getRecord())
            ->schema([
                \Filament\Infolists\Components\Section::make('Reward Profile')
                    ->schema(function () {
                        $profile = $this->getRecord()->rewardProfile;

                        if (! $profile) {
                            return [
                                \Filament\Infolists\Components\TextEntry::make('no_profile')
                                    ->label('')
                                    ->state('No reward profile found for this user.'),
                            ];
                        }

                        return [
                            \Filament\Infolists\Components\TextEntry::make('level')
                                ->label('Level')
                                ->state($profile->level),
                            \Filament\Infolists\Components\TextEntry::make('xp')
                                ->label('XP')
                                ->state($profile->xp),
                            \Filament\Infolists\Components\TextEntry::make('xp_progress')
                                ->label('Progress')
                                ->state($profile->xp_progress . '%'),
                            \Filament\Infolists\Components\TextEntry::make('points_balance')
                                ->label('Points')
                                ->state($profile->points_balance),
                            \Filament\Infolists\Components\TextEntry::make('current_streak')
                                ->label('Current Streak')
                                ->state($profile->current_streak . ' days'),
                            \Filament\Infolists\Components\TextEntry::make('longest_streak')
                                ->label('Longest Streak')
                                ->state($profile->longest_streak . ' days'),
                            \Filament\Infolists\Components\TextEntry::make('last_activity_date')
                                ->label('Last Activity')
                                ->state($profile->last_activity_date?->format('M j, Y') ?? 'N/A'),
                        ];
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
            Actions\Action::make('addXp')
                ->label('Add XP')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->form([
                    \Filament\Forms\Components\TextInput::make('xp_amount')
                        ->label('XP Amount')
                        ->numeric()
                        ->required()
                        ->minValue(1),
                    \Filament\Forms\Components\TextInput::make('reason')
                        ->label('Reason')
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $profile = $this->getRecord()->rewardProfile;

                    if (! $profile) {
                        Notification::make()
                            ->title('No Reward Profile')
                            ->warning()
                            ->body('This user has no reward profile.')
                            ->send();

                        return;
                    }

                    try {
                        DB::transaction(function () use ($profile, $data): void {
                            $profile->xp += (int) $data['xp_amount'];
                            $profile->save();
                        });

                        Notification::make()
                            ->title('XP Added')
                            ->success()
                            ->body("Added {$data['xp_amount']} XP. New total: {$profile->xp}")
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Failed to Add XP')
                            ->danger()
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
            Actions\Action::make('deductXp')
                ->label('Deduct XP')
                ->icon('heroicon-o-minus')
                ->color('warning')
                ->form([
                    \Filament\Forms\Components\TextInput::make('xp_amount')
                        ->label('XP Amount')
                        ->numeric()
                        ->required()
                        ->minValue(1),
                    \Filament\Forms\Components\TextInput::make('reason')
                        ->label('Reason')
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $profile = $this->getRecord()->rewardProfile;

                    if (! $profile) {
                        Notification::make()
                            ->title('No Reward Profile')
                            ->warning()
                            ->body('This user has no reward profile.')
                            ->send();

                        return;
                    }

                    try {
                        DB::transaction(function () use ($profile, $data): void {
                            $profile->xp = max(0, $profile->xp - (int) $data['xp_amount']);
                            $profile->save();
                        });

                        Notification::make()
                            ->title('XP Deducted')
                            ->success()
                            ->body("Deducted {$data['xp_amount']} XP. New total: {$profile->xp}")
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Failed to Deduct XP')
                            ->danger()
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
            Actions\Action::make('addPoints')
                ->label('Add Points')
                ->icon('heroicon-o-plus-circle')
                ->color('info')
                ->form([
                    \Filament\Forms\Components\TextInput::make('points_amount')
                        ->label('Points Amount')
                        ->numeric()
                        ->required()
                        ->minValue(1),
                    \Filament\Forms\Components\TextInput::make('reason')
                        ->label('Reason')
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $profile = $this->getRecord()->rewardProfile;

                    if (! $profile) {
                        Notification::make()
                            ->title('No Reward Profile')
                            ->warning()
                            ->body('This user has no reward profile.')
                            ->send();

                        return;
                    }

                    try {
                        DB::transaction(function () use ($profile, $data): void {
                            $profile->points_balance += (int) $data['points_amount'];
                            $profile->save();
                        });

                        Notification::make()
                            ->title('Points Added')
                            ->success()
                            ->body("Added {$data['points_amount']} points. New balance: {$profile->points_balance}")
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Failed to Add Points')
                            ->danger()
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
            Actions\Action::make('deductPoints')
                ->label('Deduct Points')
                ->icon('heroicon-o-minus-circle')
                ->color('danger')
                ->form([
                    \Filament\Forms\Components\TextInput::make('points_amount')
                        ->label('Points Amount')
                        ->numeric()
                        ->required()
                        ->minValue(1),
                    \Filament\Forms\Components\TextInput::make('reason')
                        ->label('Reason')
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $profile = $this->getRecord()->rewardProfile;

                    if (! $profile) {
                        Notification::make()
                            ->title('No Reward Profile')
                            ->warning()
                            ->body('This user has no reward profile.')
                            ->send();

                        return;
                    }

                    try {
                        DB::transaction(function () use ($profile, $data): void {
                            $profile->points_balance = max(0, $profile->points_balance - (int) $data['points_amount']);
                            $profile->save();
                        });

                        Notification::make()
                            ->title('Points Deducted')
                            ->success()
                            ->body("Deducted {$data['points_amount']} points. New balance: {$profile->points_balance}")
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Failed to Deduct Points')
                            ->danger()
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
        ];
    }
}
