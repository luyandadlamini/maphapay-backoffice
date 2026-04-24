<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Banking;

use App\Domain\Banking\Models\BankAccountModel;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

final class AggregatedBalanceQuery
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(mixed $rootValue, array $args): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        // IGNORE user_uuid arg entirely - always use authenticated user
        $accounts = BankAccountModel::query()
            ->where('user_uuid', $user->uuid)
            ->where('status', 'active')
            ->get();

        return $accounts->groupBy('currency')->map(function ($group, $currency) {
            return [
                'currency'      => (string) $currency,
                'total_balance' => 0.0,
                'account_count' => $group->count(),
            ];
        })->values()->all();
    }
}
