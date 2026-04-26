<?php

declare(strict_types=1);

namespace App\Support\Backoffice;

use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BackofficeWorkspaceAccess
{
    /**
     * Ordered list of all known workspaces (used for stable dashboard composition).
     *
     * @var list<string>
     */
    public const ORDERED_WORKSPACES = [
        'platform_administration',
        'finance',
        'compliance',
        'support',
    ];

    /**
     * Workspaces the user may access, in canonical order. Delegates only to {@see self::canAccess()}.
     *
     * @return list<string>
     */
    public function activeWorkspaces(?Authenticatable $user = null): array
    {
        $user ??= auth()->user();

        if ($user === null) {
            return [];
        }

        $active = [];

        foreach (self::ORDERED_WORKSPACES as $workspace) {
            if ($this->canAccess($workspace, $user)) {
                $active[] = $workspace;
            }
        }

        return $active;
    }

    public function canAccess(string $workspace, ?Authenticatable $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null) {
            return false;
        }

        return match ($workspace) {
            'platform_administration' => method_exists($user, 'hasRole') && $user->hasRole('super-admin'),
            'finance'                 => (method_exists($user, 'hasRole') && $user->hasRole('super-admin'))
                || (method_exists($user, 'can') && $user->can('approve-adjustments')),
            'compliance' => (method_exists($user, 'hasRole') && $user->hasRole('super-admin'))
                || (method_exists($user, 'hasRole') && $user->hasRole('compliance-manager')),
            'support' => (method_exists($user, 'hasRole') && $user->hasRole('super-admin'))
                || (method_exists($user, 'can') && $user->can('view-users')),
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
