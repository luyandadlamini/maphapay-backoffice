<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use App\Models\User;
use App\Support\Backoffice\AdminActionGovernance;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Passwords\PasswordBroker;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reset2fa')
                ->label('Reset 2FA')
                ->icon('heroicon-o-shield-exclamation')
                ->color('warning')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('reason')->required()->minLength(10),
                ])
                ->action(function (User $record, array $data): void {
                    $record->forceFill([
                        'two_factor_secret'         => null,
                        'two_factor_recovery_codes' => null,
                        'two_factor_confirmed_at'   => null,
                    ])->save();

                    $actorEmail = auth()->user()->email ?? 'system';

                    app(AdminActionGovernance::class)->auditDirectAction(
                        workspace: UserResource::getBackofficeWorkspace(),
                        action: 'backoffice.users.2fa_reset',
                        reason: (string) $data['reason'],
                        auditable: $record,
                        metadata: [
                            'user_uuid'   => $record->uuid,
                            'user_email'  => $record->email,
                            'actor_email' => $actorEmail,
                        ],
                        tags: 'backoffice,support,users'
                    );

                    Notification::make()
                        ->title('2FA Disabled')
                        ->success()
                        ->send();
                })
                ->visible(fn (): bool => UserResource::userCanResetCredentials()),
            Actions\Action::make('resetPassword')
                ->label('Force Password Reset')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('This will send a password reset link to the user\'s email address.')
                ->form([
                    Textarea::make('reason')->required()->minLength(10),
                ])
                ->action(function (User $record, array $data): void {
                    $record->sendPasswordResetNotification(
                        app(PasswordBroker::class)->createToken($record)
                    );

                    $actorEmail = auth()->user()->email ?? 'system';

                    app(AdminActionGovernance::class)->auditDirectAction(
                        workspace: UserResource::getBackofficeWorkspace(),
                        action: 'backoffice.users.password_reset_forced',
                        reason: (string) $data['reason'],
                        auditable: $record,
                        metadata: [
                            'user_uuid'   => $record->uuid,
                            'user_email'  => $record->email,
                            'actor_email' => $actorEmail,
                        ],
                        tags: 'backoffice,support,users'
                    );

                    Notification::make()
                        ->title('Password reset link sent')
                        ->success()
                        ->send();
                })
                ->visible(fn (): bool => UserResource::userCanResetCredentials()),
            Actions\EditAction::make()
                ->visible(false),
            Actions\Action::make('delete')
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('reason')->required()->minLength(10),
                ])
                ->visible(fn (): bool => UserResource::userCanRequestUserDeletion())
                ->action(function (User $record, array $data): void {
                    UserResource::requestUserDeletionApproval(
                        record: $record,
                        reason: (string) $data['reason'],
                    );

                    Notification::make()
                        ->title('User deletion request submitted')
                        ->warning()
                        ->send();
                }),
        ];
    }
}
