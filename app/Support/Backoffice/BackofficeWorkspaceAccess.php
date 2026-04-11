<?php

declare(strict_types=1);

namespace App\Support\Backoffice;

use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BackofficeWorkspaceAccess
{
    public function canAccess(string $workspace, ?Authenticatable $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null) {
            return false;
        }

        return match ($workspace) {
            'platform_administration' => method_exists($user, 'hasRole') && $user->hasRole('super-admin'),
            'finance' => (method_exists($user, 'hasRole') && $user->hasRole('super-admin'))
                || (method_exists($user, 'can') && $user->can('approve-adjustments')),
            'compliance' => (method_exists($user, 'hasRole') && $user->hasRole('super-admin'))
                || (method_exists($user, 'hasRole') && $user->hasRole('compliance-manager')),
            default => false,
        };
    }

    public function authorize(string $workspace, ?Authenticatable $user = null): void
    {
        if (! $this->canAccess($workspace, $user)) {
            throw new HttpException(403, 'This action is outside your workspace.');
        }
    }
}
