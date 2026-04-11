<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Pages;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\AccountService;
use App\Filament\Admin\Resources\AccountResource;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('freeze')
                ->label('Freeze Wallet')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Freeze Wallet Account')
                ->modalDescription('This will immediately prevent all transactions on this wallet. The customer will not be able to send or receive funds.')
                ->modalSubmitActionLabel('Yes, freeze wallet')
                ->form([
                    Textarea::make('reason')
                        ->label('Freeze Reason')
                        ->required()
                        ->minLength(10)
                        ->placeholder('e.g. Compliance hold — pending fraud investigation'),
                ])
                ->visible(fn (Account $record): bool => ! $record->frozen)
                ->action(function (Account $record, array $data): void {
                    try {
                        $oldValues = ['frozen' => false];

                        app(AccountService::class)->freeze($record->uuid);

                        static::adminActionGovernance()->auditDirectAction(
                            workspace: 'finance',
                            action: 'backoffice.accounts.frozen',
                            reason: $data['reason'],
                            auditable: $record,
                            oldValues: $oldValues,
                            newValues: ['frozen' => true],
                            metadata: [
                                'mode'         => 'direct_elevated',
                                'workspace'    => 'finance',
                                'reason'       => $data['reason'],
                                'account_uuid' => $record->uuid,
                                'context'      => 'account_view',
                            ],
                            tags: 'backoffice,finance,accounts'
                        );

                        Notification::make()
                            ->title('Wallet Frozen')
                            ->body("Account {$record->uuid} has been frozen.")
                            ->warning()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Failed to Freeze Wallet')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('unfreeze')
                ->label('Unfreeze Wallet')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Unfreeze Wallet Account')
                ->modalDescription('This will restore full transaction capability for this wallet.')
                ->modalSubmitActionLabel('Yes, unfreeze wallet')
                ->form([
                    Textarea::make('reason')
                        ->label('Unfreeze Reason')
                        ->required()
                        ->minLength(10)
                        ->placeholder('e.g. Exception cleared — restoring wallet activity'),
                ])
                ->visible(fn (Account $record): bool => $record->frozen)
                ->action(function (Account $record, array $data): void {
                    try {
                        $oldValues = ['frozen' => true];

                        app(AccountService::class)->unfreeze($record->uuid);

                        static::adminActionGovernance()->auditDirectAction(
                            workspace: 'finance',
                            action: 'backoffice.accounts.unfrozen',
                            reason: $data['reason'],
                            auditable: $record,
                            oldValues: $oldValues,
                            newValues: ['frozen' => false],
                            metadata: [
                                'mode'         => 'direct_elevated',
                                'workspace'    => 'finance',
                                'reason'       => $data['reason'],
                                'account_uuid' => $record->uuid,
                                'context'      => 'account_view',
                            ],
                            tags: 'backoffice,finance,accounts'
                        );

                        Notification::make()
                            ->title('Wallet Unfrozen')
                            ->body("Account {$record->uuid} has been unfrozen.")
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Failed to Unfreeze Wallet')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('requestAdjustment')
                ->label('Request Adjustment')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->requiresConfirmation(false)
                ->form([
                    Forms\Components\Select::make('type')
                        ->options(['credit' => 'Credit (Add Funds)', 'debit' => 'Debit (Remove Funds)'])
                        ->required(),
                    Forms\Components\TextInput::make('amount')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0.01)
                        ->required(),
                    Textarea::make('reason')
                        ->required()
                        ->minLength(10)
                        ->rows(3),
                    Forms\Components\FileUpload::make('attachment')
                        ->label('Supporting Document')
                        ->disk('private')
                        ->directory('adjustment-attachments')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->nullable(),
                ])
                ->action(function (Account $record, array $data): void {
                    static::adminActionGovernance()->submitApprovalRequest(
                        workspace: 'finance',
                        action: 'backoffice.accounts.request_adjustment',
                        reason: $data['reason'],
                        targetType: Account::class,
                        targetIdentifier: (string) $record->getKey(),
                        payload: [
                            'account_uuid'    => $record->uuid,
                            'adjustment_type' => $data['type'],
                            'amount_minor'    => (int) round((float) $data['amount'] * 100),
                            'attachment_path' => $data['attachment'] ?? null,
                            'context'         => 'account_view',
                        ],
                        metadata: [
                            'mode' => 'request_approve',
                        ],
                    );

                    Notification::make()
                        ->title('Adjustment request submitted')
                        ->body('The adjustment request has been submitted for approval.')
                        ->success()
                        ->send();
                }),

            Action::make('replayProjector')
                ->label('Replay Projector')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Replay Account Projector')
                ->modalDescription('This rebuilds this account\'s projected balance from the event stream. Use only if the balance appears incorrect.')
                ->modalSubmitActionLabel('Yes, replay projector')
                ->form([
                    Textarea::make('reason')
                        ->label('Reason for Replay')
                        ->required()
                        ->minLength(10)
                        ->placeholder('Explain why the projector needs to be rebuilt'),
                ])
                ->action(function (Account $record, array $data): void {
                    static::adminActionGovernance()->submitApprovalRequest(
                        workspace: 'finance',
                        action: 'backoffice.accounts.replay_projector',
                        reason: $data['reason'],
                        targetType: Account::class,
                        targetIdentifier: (string) $record->getKey(),
                        payload: [
                            'account_uuid' => $record->uuid,
                            'replay_scope' => 'account_projectors',
                            'context'      => 'account_view',
                        ],
                        metadata: [
                            'mode' => 'request_approve',
                        ],
                    );

                    Notification::make()
                        ->title('Projector replay request submitted')
                        ->body('The projector replay request has been submitted for approval.')
                        ->warning()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AccountResource\Widgets\AccountStatsOverview::class,
        ];
    }

    public static function adminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    public static function authorizeWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize('finance');
    }
}
