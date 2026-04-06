<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Models\Thread;
use App\Filament\Admin\Resources\SocialMoneyResource\Pages;
use App\Domain\Fraud\Models\AnomalyDetection;
use Exception;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SocialMoneyResource extends Resource
{
    protected static ?string $model = Thread::class;

    protected static ?string $modelLabel = 'Social Group Thread';

    protected static ?string $pluralModelLabel = 'Social Group Threads';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Support Hub';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool { return false; }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('moderate-social') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('messages', 'activeParticipants'))
            ->columns([
                TextColumn::make('name')
                    ->label('Thread / Group')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'group' => 'info',
                        'direct' => 'gray',
                        default => 'secondary',
                    }),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('active_participants_count')
                    ->label('Members'),
                TextColumn::make('messages_count')
                    ->label('Messages'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'group'  => 'Group',
                        'direct' => 'Direct',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('flag_compliance')
                    ->label('Flag for Review')
                    ->icon('heroicon-o-flag')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn () => auth()->user()?->can('moderate-social'))
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for flagging')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (Thread $record, array $data): void {
                        try {
                            AnomalyDetection::create([
                                'entity_id'        => $record->id,
                                'entity_type'      => Thread::class,
                                'anomaly_type'     => \App\Domain\Fraud\Enums\AnomalyType::cases()[0],
                                'detection_method' => \App\Domain\Fraud\Enums\DetectionMethod::cases()[0],
                                'status'           => \App\Domain\Fraud\Enums\AnomalyStatus::Detected,
                                'anomaly_score'    => 50,
                                'confidence'       => 0.5,
                                'severity'         => 'medium',
                                'is_real_time'     => false,
                                'explanation'      => ['reason' => $data['reason'], 'flagged_by' => auth()->id()],
                                'user_id'          => $record->created_by,
                            ]);

                            Notification::make()
                                ->title('Thread flagged for compliance review')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()->title('Failed')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('block_social_profile')
                    ->label('Block Profile')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This will freeze the creator\'s main account, preventing all transactions. Are you sure?')
                    ->visible(fn () => auth()->user()?->can('moderate-social') && auth()->user()?->can('freeze-accounts'))
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for blocking')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (Thread $record, array $data): void {
                        try {
                            if ($record->creator) {
                                foreach ($record->creator->accounts ?? [] as $account) {
                                    $account->update(['frozen' => true]);
                                }
                                if (function_exists('activity')) {
                                    activity()
                                        ->performedOn($record)
                                        ->causedBy(auth()->user())
                                        ->withProperties(['reason' => $data['reason']])
                                        ->log('social_profile_blocked');
                                }
                            }
                            Notification::make()->title('Social profile blocked')->success()->send();
                        } catch (Exception $e) {
                            Notification::make()->title('Failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialMoney::route('/'),
        ];
    }
}
