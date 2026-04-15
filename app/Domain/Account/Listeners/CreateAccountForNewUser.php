<?php

declare(strict_types=1);

namespace App\Domain\Account\Listeners;

use App\Domain\Account\DataObjects\Account;
use App\Domain\Account\Models\Account as AccountModel;
use App\Domain\Account\Services\AccountMembershipService;
use App\Domain\Account\Services\AccountService;
use App\Models\Tenant;
use Exception;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Tenancy;

class CreateAccountForNewUser
{
    public function __construct(
        private AccountService $accountService,
        private AccountMembershipService $accountMembershipService,
        private Tenancy $tenancy,
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        /** @var \App\Models\User $user */
        $user = $event->user;
        try {
            $team = $user->ownedTeams()
                ->where('personal_team', true)
                ->first();

            if ($team === null) {
                throw new Exception('Personal team not found for registered user.');
            }

            $tenant = Tenant::query()->firstOrCreate(
                ['team_id' => $team->id],
                [
                    'name' => $team->name,
                    'plan' => 'default',
                ],
            );

            $this->tenancy->initialize($tenant);

            $account = AccountModel::query()
                ->where('user_uuid', $user->uuid)
                ->orderBy('created_at')
                ->first();

            if ($account === null) {
                $accountUuid = $this->accountService->createDirect(
                    new Account(
                        name: 'Maphapay Wallet',
                        userUuid: $user->uuid
                    )
                );

                $account = AccountModel::query()
                    ->where('uuid', $accountUuid)
                    ->first();
            }

            if ($account === null) {
                throw new Exception('Failed to resolve newly created wallet account.');
            }

            $this->accountMembershipService->createOwnerMembership($user, (string) $tenant->id, $account);

            Log::info(
                'Created Maphapay Wallet for new user',
                [
                    'user_uuid'  => $user->uuid,
                    'user_email' => $user->email,
                ]
            );
        } catch (Exception $e) {
            // Log the error but don't prevent user registration
            Log::error(
                'Failed to create Maphapay Wallet for new user',
                [
                    'user_uuid' => $user->uuid ?? 'unknown',
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]
            );
        } finally {
            if ($this->tenancy->initialized) {
                $this->tenancy->end();
            }
        }
    }
}
