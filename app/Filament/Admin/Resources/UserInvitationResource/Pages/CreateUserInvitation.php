<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserInvitationResource\Pages;

use App\Domain\User\Services\UserInvitationService;
use App\Filament\Admin\Resources\UserInvitationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUserInvitation extends CreateRecord
{
    protected static string $resource = UserInvitationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        UserInvitationResource::authorizeWorkspace();

        /** @var \App\Models\User $user */
        $user = auth()->user();

        $service = app(UserInvitationService::class);
        $invitation = $service->invite(
            (string) $data['email'],
            (string) $data['role'],
            $user,
        );

        UserInvitationResource::adminActionGovernance()->auditDirectAction(
            workspace: UserInvitationResource::getBackofficeWorkspace(),
            action: 'backoffice.user_invitations.created',
            reason: (string) $data['reason'],
            auditable: $invitation,
            metadata: [
                'email'       => $invitation->email,
                'role'        => $invitation->role,
                'actor_email' => $user->email ?? 'system',
            ],
            tags: 'backoffice,platform,user-invitations'
        );

        Notification::make()
            ->title('Invitation sent to ' . $data['email'])
            ->success()
            ->send();

        return $invitation;
    }

    protected function getRedirectUrl(): string
    {
        return UserInvitationResource::getUrl('index');
    }
}
