<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Wallet;

use App\Domain\Wallet\Models\MultiSigWallet;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

class WalletQuery
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $rootValue, array $args): MultiSigWallet
    {
        $user = Auth::user();

        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        /** @var MultiSigWallet|null $wallet */
        $wallet = MultiSigWallet::query()
            ->where('id', $args['id'])
            ->where('user_id', $user->id)
            ->first();

        if (! $wallet) {
            throw new ModelNotFoundException('Wallet not found or access denied.');
        }

        return $wallet;
    }
}
