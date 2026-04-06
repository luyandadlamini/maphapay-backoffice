<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SystemUserService
{
    private ?User $systemUser = null;

    private ?User $suspenseUser = null;

    private ?User $treasuryUser = null;

    private ?User $poolUser = null;

    public function getSystemUser(): User
    {
        if (! $this->systemUser) {
            $this->systemUser = $this->getOrCreateSystemUser(
                config('system_users.email.system'),
                'Maphapay System'
            );
        }

        return $this->systemUser;
    }

    public function getSuspenseUser(): User
    {
        if (! $this->suspenseUser) {
            $this->suspenseUser = $this->getOrCreateSystemUser(
                config('system_users.email.suspense'),
                'Suspense Account'
            );
        }

        return $this->suspenseUser;
    }

    public function getTreasuryUser(): User
    {
        if (! $this->treasuryUser) {
            $this->treasuryUser = $this->getOrCreateSystemUser(
                config('system_users.email.treasury'),
                'Treasury Account'
            );
        }

        return $this->treasuryUser;
    }

    public function getPoolUser(): User
    {
        if (! $this->poolUser) {
            $this->poolUser = $this->getOrCreateSystemUser(
                config('system_users.email.pool'),
                'Liquidity Pool System'
            );
        }

        return $this->poolUser;
    }

    public function getUserByType(string $type): User
    {
        return match ($type) {
            'system'   => $this->getSystemUser(),
            'suspense' => $this->getSuspenseUser(),
            'treasury' => $this->getTreasuryUser(),
            'pool'     => $this->getPoolUser(),
            default    => $this->getSystemUser(),
        };
    }

    private function getOrCreateSystemUser(string $email, string $name): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name'     => $name,
                'password' => Hash::make(Str::uuid()->toString()),
                'uuid'     => $this->getUuidForType($email),
            ]
        );
    }

    private function getUuidForType(string $email): ?string
    {
        $type = match ($email) {
            config('system_users.email.system')   => 'system',
            config('system_users.email.suspense') => 'suspense',
            config('system_users.email.treasury') => 'treasury',
            config('system_users.email.pool')     => 'pool',
            default                               => null,
        };

        return $type ? config("system_users.uuid.{$type}") : null;
    }
}
