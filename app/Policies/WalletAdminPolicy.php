<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class WalletAdminPolicy
{
    /**
     * Decide whether the user can perform privileged wallet-admin actions
     * (fund mock account, unlink a user's wallet linking).
     */
    public function manageMockWallets(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('wallet_ops');
    }
}
