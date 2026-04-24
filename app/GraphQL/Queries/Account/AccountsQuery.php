<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Account;

use App\Domain\Account\Models\Account;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AccountsQuery
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $rootValue, array $args): Builder
    {
        $user = Auth::user();

        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return Account::query()
            ->where('user_uuid', $user->uuid)
            ->orderBy('created_at', 'desc');
    }
}
