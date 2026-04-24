<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Account;

use App\Domain\Account\Models\Account;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

class AccountQuery
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $rootValue, array $args): Account
    {
        $user = Auth::user();

        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        /** @var Account|null $account */
        $account = Account::query()
            ->where('uuid', $args['id'])
            ->where('user_uuid', $user->uuid)
            ->first();

        if (! $account) {
            throw new ModelNotFoundException('Account not found or access denied.');
        }

        return $account;
    }
}
