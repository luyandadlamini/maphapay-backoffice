<?php

declare(strict_types=1);

namespace App\Domain\Account\Listeners;

use App\Domain\Account\Services\AccountProvisioningService;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Listens for the standard Laravel {@see Registered} event and delegates
 * to {@see AccountProvisioningService}.
 *
 * Historical note: this listener used to inline-implement Team/Tenant/
 * Account/Membership provisioning, but only the Fortify-driven registration
 * paths fire `Registered` — mobile OTP, OAuth, and team-invite paths
 * silently skipped it, leaving real users with bare User rows. The
 * production diagnostic on 2026-05-18 (Mickey/Khanya/Sandelwe missing
 * the entire quintet) drove the refactor to a shared service so every
 * caller can invoke it directly.
 */
class CreateAccountForNewUser
{
    public function __construct(
        private readonly AccountProvisioningService $provisioningService,
    ) {
    }

    public function handle(Registered $event): void
    {
        /** @var User $user */
        $user = $event->user;

        try {
            $this->provisioningService->ensureProvisioned($user);
        } catch (Throwable $exception) {
            // Do not block registration on provisioning failure — log and
            // rely on operations to follow up via:
            //   php artisan accounts:repair-owner-membership <email>
            Log::error('Failed to provision central directory for new user', [
                'user_uuid' => $user->uuid ?? 'unknown',
                'error'     => $exception->getMessage(),
            ]);
        }
    }
}
