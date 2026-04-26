<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Payment;

use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PaymentQuery
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

        return PaymentTransaction::query()
            ->whereHas('account', fn ($q) => $q->where('user_uuid', $user->uuid))
            ->orderBy('created_at', 'desc');
    }
}
