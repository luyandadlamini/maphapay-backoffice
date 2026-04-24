<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Compliance;

use App\Domain\Compliance\Models\KycVerification;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;

class KycVerificationsQuery
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $rootValue, array $args): Builder
    {
        $user = Auth::user();
        if (! $user || ! Gate::allows('superadmin')) {
            throw new AuthenticationException('Unauthorized.');
        }

        return KycVerification::query()->orderBy('created_at', 'desc');
    }
}
