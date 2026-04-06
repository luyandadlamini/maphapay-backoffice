<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Fraud\Models\AnomalyDetection;
use App\Filament\Admin\Resources\ReferralResource\Pages;
use App\Models\Referral;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReferralResource extends Resource
{
    protected static ?string $model = Referral::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'Growth & Rewards';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Referrals';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('compliance-manager'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('referrer.name')
                    ->label('Referrer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('referee.name')
                    ->label('Referred User')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('referralCode.code')
                    ->label('Referral Code')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(
                        fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'completed' => 'success',
                            'rewarded' => 'info',
                            'flagged' => 'danger',
                            default => 'gray',
                        }
                    ),

                Tables\Columns\TextColumn::make('reward_amount')
                    ->label('Reward')
                    ->money('SZL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                        'rewarded' => 'Rewarded',
                        'flagged' => 'Flagged',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('flagAsFraudulent')
                    ->label('Flag as Fraudulent')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Flag Referral as Fraudulent')
                    ->modalDescription('This will disable the referral and create an anomaly detection record.')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function ($record, array $data): void {
                        $record->update(['status' => 'flagged']);

                        if (class_exists(AnomalyDetection::class)) {
                            AnomalyDetection::create([
                                'type' => 'referral_fraud',
                                'severity' => 'high',
                                'description' => $data['reason'],
                                'metadata' => [
                                    'referral_id' => $record->id,
                                    'referrer_id' => $record->referrer_id,
                                    'referee_id' => $record->referee_id,
                                ],
                            ]);
                        }

                        Notification::make()
                            ->title('Referral flagged')
                            ->body('The referral has been flagged as fraudulent.')
                            ->danger()
                            ->send();
                    }),

                Tables\Actions\Action::make('viewReferralTree')
                    ->label('View Tree')
                    ->icon('heroicon-o-user-group')
                    ->color('primary')
                    ->modalHeading('Referral Chain')
                    ->modalContent(function ($record): string {
                        $html = '<div class="space-y-2">';
                        $html .= '<p><strong>Referrer:</strong> '.($record->referrer->name ?? 'N/A').'</p>';
                        $html .= '<p><strong>Referred:</strong> '.($record->referee->name ?? 'N/A').'</p>';
                        $html .= '<p><strong>Status:</strong> '.$record->status.'</p>';
                        $html .= '<p><strong>Created:</strong> '.$record->created_at->format('Y-m-d H:i:s').'</p>';
                        $html .= '</div>';

                        return $html;
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('flagSelected')
                    ->label('Flag as Fraudulent')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->update(['status' => 'flagged'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferrals::route('/'),
        ];
    }
}
