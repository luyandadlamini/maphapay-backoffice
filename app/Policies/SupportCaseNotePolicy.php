<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Support\Models\SupportCaseNote;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SupportCaseNotePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view-support-cases') || $user->hasRole('super-admin');
    }

    public function view(User $user, SupportCaseNote $note): bool
    {
        return $user->can('view-support-cases') || $user->hasRole('super-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('create-support-cases') || $user->hasRole('super-admin');
    }

    public function update(User $user, SupportCaseNote $note): bool
    {
        return $user->id === $note->author_id || $user->hasRole('super-admin');
    }

    public function delete(User $user, SupportCaseNote $note): bool
    {
        return $user->id === $note->author_id || $user->hasRole('super-admin');
    }
}
