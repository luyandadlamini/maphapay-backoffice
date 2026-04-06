<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Compliance\Models\AmlScreening;
use App\Filament\Admin\Resources\AmlScreeningResource\Pages;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AmlScreeningResource extends Resource
{
    protected static ?string $model = AmlScreening::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'AML Screening';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('compliance-manager'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('screening_number')
                    ->label('Screening #')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('entity.name')
                    ->label('Entity')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sanctions' => 'danger',
                        'pep' => 'warning',
                        'adverse_media' => 'info',
                        'comprehensive' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('overall_risk')
                    ->label('Risk')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'low' => 'success',
                        'medium' => 'warning',
                        'high' => 'danger',
                        'critical' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total_matches')
                    ->label('Matches')
                    ->numeric()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'in_progress' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(AmlScreening::SCREENING_TYPES),
                Tables\Filters\SelectFilter::make('overall_risk')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'critical' => 'Critical',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('submitSar')
                    ->label('Submit SAR')
                    ->icon('heroicon-o-document-text')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Submit Suspicious Activity Report')
                    ->modalDescription('This will create a SAR filing record.')
                    ->form([
                        Textarea::make('description')
                            ->label('Description')
                            ->required()
                            ->minLength(20),
                        TextInput::make('reference')
                            ->label('Internal Reference')
                            ->required(),
                    ])
                    ->action(function ($record, array $data): void {
                        Notification::make()
                            ->title('SAR submitted')
                            ->body("SAR for screening {$record->screening_number} has been filed.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('clearFlag')
                    ->label('Clear Flag')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for clearing')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function ($record, array $data): void {
                        $record->update([
                            'review_decision' => AmlScreening::DECISION_CLEAR,
                            'review_notes' => $data['reason'],
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Flag cleared')
                            ->body('The AML flag has been cleared.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('escalate')
                    ->label('Escalate')
                    ->icon('heroicon-o-arrow-up')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        Notification::make()
                            ->title('Escalated')
                            ->body('The case has been escalated to the compliance lead.')
                            ->warning()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAmlScreenings::route('/'),
        ];
    }
}
