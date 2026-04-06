<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
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
                    \Filament\Forms\Components\Textarea::make('reason')->required(),
                ])
                ->action(function (User $record, array $data): void {
                    $record->forceFill([
                        'two_factor_secret'         => null,
                        'two_factor_recovery_codes' => null,
                        'two_factor_confirmed_at'   => null,
                    ])->save();

                    if (function_exists('activity')) {
                        activity()
                            ->performedOn($record)
                            ->causedBy(auth()->user())
                            ->withProperties(['reason' => $data['reason']])
                            ->log('reset_2fa');
                    }

                    Notification::make()
                        ->title('2FA Disabled')
                        ->success()
                        ->send();
                })
                ->visible(fn (): bool => auth()->user()?->can('reset-user-password') ?? false),
            Actions\Action::make('resetPassword')
                ->label('Force Password Reset')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('This will send a password reset link to the user\'s email address.')
                ->action(function (User $record): void {
                    $record->sendPasswordResetNotification(
                        app(PasswordBroker::class)->createToken($record)
                    );
                    Notification::make()
                        ->title('Password reset link sent')
                        ->success()
                        ->send();
                })
                ->visible(fn (): bool => auth()->user()?->can('reset-user-password') ?? false),
            Actions\EditAction::make()
                ->visible(fn (): bool => auth()->user()?->can('manage-users') ?? false),
            Actions\DeleteAction::make()
                ->visible(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false),
        ];
    }
}
