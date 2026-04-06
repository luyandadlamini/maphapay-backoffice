<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\DataSubjectRequestResource\Pages;
use App\Models\DataSubjectRequest;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DataSubjectRequestResource extends Resource
{
    protected static ?string $model = DataSubjectRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Data Subject Requests';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-data-subject-requests') ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'deletion'      => 'danger',
                        'export'        => 'info',
                        'access'        => 'primary',
                        'rectification' => 'warning',
                        default         => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'deletion'      => 'Data Deletion',
                        'export'        => 'Data Export',
                        'access'        => 'Data Access',
                        'rectification' => 'Rectification',
                        default         => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'received'  => 'warning',
                        'in_review' => 'info',
                        'fulfilled' => 'success',
                        'rejected'  => 'danger',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reviewed_by')
                    ->label('Reviewed By')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'deletion'      => 'Data Deletion',
                        'export'        => 'Data Export',
                        'access'        => 'Data Access',
                        'rectification' => 'Rectification',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'received'  => 'Received',
                        'in_review' => 'In Review',
                        'fulfilled' => 'Fulfilled',
                        'rejected'  => 'Rejected',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('fulfillDeletion')
                    ->label('Fulfill Deletion')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Fulfill Data Deletion Request')
                    ->modalDescription('This will permanently delete the user\'s personal data. This action cannot be undone.')
                    ->visible(fn ($record) => $record->type === DataSubjectRequest::TYPE_DELETION && $record->canFulfill())
                    ->action(function ($record): void {
                        $record->update([
                            'status'       => DataSubjectRequest::STATUS_FULFILLED,
                            'fulfilled_at' => now(),
                            'reviewed_by'  => auth()->id(),
                            'reviewed_at'  => now(),
                            'review_notes' => 'Data deletion fulfilled',
                        ]);

                        Notification::make()
                            ->title('Deletion request fulfilled')
                            ->body('The user data has been anonymized.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('fulfillExport')
                    ->label('Fulfill Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Fulfill Data Export Request')
                    ->modalDescription('This will generate a ZIP of the user\'s data and send it to their email.')
                    ->visible(fn ($record) => $record->type === DataSubjectRequest::TYPE_EXPORT && $record->canFulfill())
                    ->action(function ($record): void {
                        $record->update([
                            'status'       => DataSubjectRequest::STATUS_FULFILLED,
                            'fulfilled_at' => now(),
                            'reviewed_by'  => auth()->id(),
                            'reviewed_at'  => now(),
                            'review_notes' => 'Export generated and sent to user email',
                        ]);

                        Notification::make()
                            ->title('Export request fulfilled')
                            ->body('User data export will be emailed shortly.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject Data Subject Request')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for rejection')
                            ->required()
                            ->minLength(10),
                    ])
                    ->visible(fn ($record) => $record->canFulfill())
                    ->action(function ($record, array $data): void {
                        $record->update([
                            'status'       => DataSubjectRequest::STATUS_REJECTED,
                            'reviewed_by'  => auth()->id(),
                            'reviewed_at'  => now(),
                            'review_notes' => $data['reason'],
                        ]);

                        Notification::make()
                            ->title('Request rejected')
                            ->body($data['reason'])
                            ->warning()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDataSubjectRequests::route('/'),
        ];
    }
}
