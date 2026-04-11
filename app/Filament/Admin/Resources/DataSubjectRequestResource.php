<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\DataSubjectRequestResource\Pages;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use App\Models\DataSubjectRequest;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DataSubjectRequestResource extends Resource
{
    use HasBackofficeWorkspace;

    protected static ?string $model = DataSubjectRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Data Subject Requests';

    protected static string $backofficeWorkspace = 'compliance';

    public static function canViewAny(): bool
    {
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
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
                    ->form([
                        Textarea::make('reason')
                            ->label('Approval evidence')
                            ->required()
                            ->minLength(10),
                    ])
                    ->visible(fn ($record) => $record->type === DataSubjectRequest::TYPE_DELETION && $record->canFulfill())
                    ->action(function ($record, array $data): void {
                        static::requestDeletionFulfillmentApproval($record, (string) $data['reason']);

                        Notification::make()
                            ->title('Deletion fulfillment request submitted')
                            ->body('The deletion request has been queued for compliance approval.')
                            ->warning()
                            ->send();
                    }),

                Tables\Actions\Action::make('fulfillExport')
                    ->label('Fulfill Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Fulfill Data Export Request')
                    ->modalDescription('This will generate a ZIP of the user\'s data and send it to their email.')
                    ->form([
                        Textarea::make('reason')
                            ->label('Release evidence')
                            ->required()
                            ->minLength(10),
                    ])
                    ->visible(fn ($record) => $record->type === DataSubjectRequest::TYPE_EXPORT && $record->canFulfill())
                    ->action(function ($record, array $data): void {
                        static::fulfillExportRequest($record, (string) $data['reason']);

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
                        static::rejectRequest($record, (string) $data['reason']);

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

    public static function requestDeletionFulfillmentApproval(DataSubjectRequest $record, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.data_subject_requests.fulfill_deletion',
            reason: $reason,
            targetType: DataSubjectRequest::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'request_type' => $record->type,
                'current_status' => $record->status,
                'requested_state' => DataSubjectRequest::STATUS_FULFILLED,
                'user_id' => $record->user_id,
                'evidence' => [
                    'reason' => $reason,
                ],
            ],
            metadata: [
                'delivery' => 'approval_required_before_deletion',
            ],
        );
    }

    public static function fulfillExportRequest(DataSubjectRequest $record, string $reason): void
    {
        static::authorizeWorkspace();

        $oldValues = static::reviewState($record);

        $record->update([
            'status' => DataSubjectRequest::STATUS_FULFILLED,
            'fulfilled_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ]);

        $record->refresh();

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.data_subject_requests.export_fulfilled',
            reason: $reason,
            auditable: $record,
            oldValues: $oldValues,
            newValues: static::reviewState($record),
            metadata: [
                'request_type' => $record->type,
                'user_id' => $record->user_id,
                'fulfilled_at' => $record->fulfilled_at?->toIso8601String(),
            ],
            tags: 'backoffice,compliance,data-subject-requests'
        );
    }

    public static function rejectRequest(DataSubjectRequest $record, string $reason): void
    {
        static::authorizeWorkspace();

        $oldValues = static::reviewState($record);

        $record->update([
            'status' => DataSubjectRequest::STATUS_REJECTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ]);

        $record->refresh();

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.data_subject_requests.rejected',
            reason: $reason,
            auditable: $record,
            oldValues: $oldValues,
            newValues: static::reviewState($record),
            metadata: [
                'request_type' => $record->type,
                'user_id' => $record->user_id,
            ],
            tags: 'backoffice,compliance,data-subject-requests'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function reviewState(DataSubjectRequest $record): array
    {
        return [
            'status' => $record->status,
            'review_notes' => $record->review_notes,
            'reviewed_by' => $record->reviewed_by,
            'reviewed_at' => $record->reviewed_at?->toIso8601String(),
            'fulfilled_at' => $record->fulfilled_at?->toIso8601String(),
        ];
    }

    public static function adminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    public static function authorizeWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());
    }
}
