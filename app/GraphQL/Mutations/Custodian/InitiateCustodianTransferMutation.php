<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\Custodian;

use App\Domain\Account\DataObjects\Money;
use App\Domain\Custodian\Models\CustodianAccount;
use App\Domain\Custodian\Models\CustodianTransfer;
use App\Domain\Custodian\Services\CustodianAccountService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

final class InitiateCustodianTransferMutation
{
    public function __construct(
        private readonly CustodianAccountService $custodianAccountService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $rootValue, array $args): CustodianTransfer
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        /** @var CustodianAccount $fromAccount */
        $fromAccount = CustodianAccount::query()
            ->where('account_uuid', $args['from_account_uuid'])
            ->firstOrFail();

        // Verify from_account belongs to authenticated user
        if ($fromAccount->user_uuid !== $user->uuid) {
            throw new AuthorizationException('You do not own the source account.');
        }

        /** @var CustodianAccount $toAccount */
        $toAccount = CustodianAccount::query()
            ->where('account_uuid', $args['to_account_uuid'])
            ->firstOrFail();

        $transferId = $this->custodianAccountService->initiateTransfer(
            fromAccount: $fromAccount,
            toAccount: $toAccount,
            amount: new Money((int) ($args['amount'] * 100)),
            assetCode: $args['asset_code'],
            reference: $args['reference'] ?? '',
        );

        /** @var CustodianTransfer */
        return CustodianTransfer::findOrFail($transferId);
    }
}
