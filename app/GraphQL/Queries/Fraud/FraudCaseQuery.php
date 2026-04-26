<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Fraud;

use App\Domain\Fraud\Models\FraudCase;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class FraudCaseQuery
{
    /**
     * @return Builder<FraudCase>
     */
    public function __invoke(mixed $rootValue, array $args): Builder
    {
        $user = Auth::user();
        if (! $user || ! Gate::allows('superadmin')) {
            throw new AuthenticationException('Unauthorized.');
        }

        return FraudCase::query()->orderBy('created_at', 'desc');
    }
}
