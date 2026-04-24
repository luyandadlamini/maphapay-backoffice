<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\Stablecoin;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Stablecoin\Models\StablecoinReserve;
use App\Domain\Stablecoin\Workflows\MintStablecoinWorkflow;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Workflow\WorkflowStub;

class MintStablecoinMutation
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $rootValue, array $args): StablecoinReserve
    {
        $user = Auth::user();

        if (! $user || ! Gate::allows('superadmin')) {
            throw new AuthenticationException('Unauthorized. Superadmin access required.');
        }

        $accountUuid = AccountUuid::fromString($args['account_uuid'] ?? $user->uuid);

        $workflow = WorkflowStub::make(MintStablecoinWorkflow::class);
        $workflow->start(
            $accountUuid,
            $args['stablecoin_code'],
            $args['collateral_asset_code'] ?? $args['stablecoin_code'],
            (int) $args['amount'],
            (int) $args['amount'],
        );

        // Return the read-model projection or create a fallback record.
        return StablecoinReserve::create([
            'reserve_id'      => Str::uuid()->toString(),
            'pool_id'         => $args['pool_id'],
            'stablecoin_code' => $args['stablecoin_code'],
            'asset_code'      => $args['stablecoin_code'],
            'amount'          => $args['amount'],
            'value_usd'       => $args['amount'],
            'custodian_type'  => 'internal',
            'status'          => 'active',
        ]);
    }
}
