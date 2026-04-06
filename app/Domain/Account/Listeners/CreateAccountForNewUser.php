<?php

declare(strict_types=1);

namespace App\Domain\Account\Listeners;

use App\Domain\Account\DataObjects\Account;
use App\Domain\Account\Services\AccountService;
use Exception;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

class CreateAccountForNewUser
{
    public function __construct(
        private AccountService $accountService
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
            // Create the user's main Maphapay Wallet directly (no workflow needed)
            $this->accountService->createDirect(
                new Account(
                    name: 'Maphapay Wallet',
                    userUuid: $user->uuid
                )
            );

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
        }
    }
}
