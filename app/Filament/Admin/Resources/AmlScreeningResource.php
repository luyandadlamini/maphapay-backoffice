<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Compliance\Models\AmlScreening;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\AmlScreeningResource\Pages;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AmlScreeningResource extends Resource
{
    use HasBackofficeWorkspace;

    protected static ?string $model = AmlScreening::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'AML Screening';

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
                        'sanctions'     => 'danger',
                        'pep'           => 'warning',
                        'adverse_media' => 'info',
                        'comprehensive' => 'primary',
                        default         => 'gray',
                    }),

                Tables\Columns\TextColumn::make('overall_risk')
                    ->label('Risk')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'low'      => 'success',
                        'medium'   => 'warning',
                        'high'     => 'danger',
                        'critical' => 'danger',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total_matches')
                    ->label('Matches')
                    ->numeric()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'     => 'warning',
                        'in_progress' => 'info',
                        'completed'   => 'success',
                        'failed'      => 'danger',
                        default       => 'gray',
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
                        'low'      => 'Low',
                        'medium'   => 'Medium',
                        'high'     => 'High',
                        'critical' => 'Critical',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'     => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed'   => 'Completed',
                        'failed'      => 'Failed',
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
                        static::requestSarApproval($record, [
                            'description' => (string) $data['description'],
                            'reference'   => (string) $data['reference'],
                        ]);

                        Notification::make()
                            ->title('SAR approval request submitted')
                            ->body("SAR filing for screening {$record->screening_number} has been queued for approval.")
                            ->warning()
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
                        static::clearFlag($record, (string) $data['reason']);

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
                    ->form([
                        Textarea::make('reason')
                            ->label('Escalation evidence')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function ($record, array $data): void {
                        static::escalateScreening($record, (string) $data['reason']);

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

    /**
     * @param  array{description: string, reference: string}  $data
     */
    public static function requestSarApproval(AmlScreening $record, array $data): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.aml_screenings.submit_sar',
            reason: (string) $data['description'],
            targetType: AmlScreening::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'screening_number'  => $record->screening_number,
                'overall_risk'      => $record->overall_risk,
                'total_matches'     => $record->total_matches,
                'confirmed_matches' => $record->confirmed_matches,
                'sar_reference'     => (string) $data['reference'],
                'requested_state'   => 'sar_pending_approval',
                'evidence'          => [
                    'description' => (string) $data['description'],
                    'reference'   => (string) $data['reference'],
                ],
            ],
            metadata: [
                'screening_type' => $record->type,
                'decision'       => 'submit_sar',
            ],
        );
    }

    public static function clearFlag(AmlScreening $record, string $reason): void
    {
        static::authorizeWorkspace();

        $oldValues = static::reviewState($record);

        $record->update([
            'review_decision' => AmlScreening::DECISION_CLEAR,
            'review_notes'    => $reason,
            'reviewed_by'     => auth()->id(),
            'reviewed_at'     => now(),
        ]);

        $record->refresh();

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.aml_screenings.flag_cleared',
            reason: $reason,
            auditable: $record,
            oldValues: $oldValues,
            newValues: static::reviewState($record),
            metadata: [
                'screening_number' => $record->screening_number,
                'decision'         => AmlScreening::DECISION_CLEAR,
                'overall_risk'     => $record->overall_risk,
                'total_matches'    => $record->total_matches,
            ],
            tags: 'backoffice,compliance,aml-screenings'
        );
    }

    public static function escalateScreening(AmlScreening $record, string $reason): void
    {
        static::authorizeWorkspace();

        $oldValues = static::reviewState($record);

        $record->update([
            'review_decision' => AmlScreening::DECISION_ESCALATE,
            'review_notes'    => $reason,
            'reviewed_by'     => auth()->id(),
            'reviewed_at'     => now(),
        ]);

        $record->refresh();

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.aml_screenings.escalated',
            reason: $reason,
            auditable: $record,
            oldValues: $oldValues,
            newValues: static::reviewState($record),
            metadata: [
                'screening_number' => $record->screening_number,
                'decision'         => AmlScreening::DECISION_ESCALATE,
                'overall_risk'     => $record->overall_risk,
                'total_matches'    => $record->total_matches,
            ],
            tags: 'backoffice,compliance,aml-screenings'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function reviewState(AmlScreening $record): array
    {
        return [
            'review_decision' => $record->review_decision,
            'review_notes'    => $record->review_notes,
            'reviewed_by'     => $record->reviewed_by,
            'reviewed_at'     => $record->reviewed_at?->toIso8601String(),
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
