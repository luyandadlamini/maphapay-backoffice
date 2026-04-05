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

    protected function getHeaderActions(): array
    {
        return [
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
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
