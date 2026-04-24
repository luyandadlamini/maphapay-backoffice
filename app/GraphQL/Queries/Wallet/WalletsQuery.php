<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Wallet;

use App\Domain\Wallet\Models\MultiSigWallet;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class WalletsQuery
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

        return MultiSigWallet::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');
    }
}
