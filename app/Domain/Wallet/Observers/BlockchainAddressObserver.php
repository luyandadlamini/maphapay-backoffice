<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Observers;

use App\Domain\Account\Models\BlockchainAddress;
use App\Domain\Wallet\Services\HeliusWebhookSyncService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Observes BlockchainAddress model events to sync Solana
 * addresses with external webhook providers (Helius).
 *
 * Queued via afterCommit to avoid blocking user registration
 * and to ensure the address is persisted before sync.
 */
class BlockchainAddressObserver
{
    public function __construct(
        private readonly HeliusWebhookSyncService $heliusSync,
    ) {
    }

    public function created(BlockchainAddress $address): void
    {
        if ($address->chain !== 'solana' || ! $address->is_active) {
            return;
        }

        // Dispatch async to avoid blocking registration with Helius HTTP call
        dispatch(function () use ($address): void {
            try {
                $this->heliusSync->addAddress($address->address);
            } catch (Throwable $e) {
                Log::error('Helius: Failed to sync new Solana address', [
                    'address' => $address->address,
                    'error'   => $e->getMessage(),
                ]);
            }
        })->afterCommit();
    }

    public function deleted(BlockchainAddress $address): void
    {
        if ($address->chain !== 'solana') {
            return;
        }

        dispatch(function () use ($address): void {
            try {
                $this->heliusSync->removeAddress($address->address);
            } catch (Throwable $e) {
                Log::error('Helius: Failed to remove Solana address', [
                    'address' => $address->address,
                    'error'   => $e->getMessage(),
                ]);
            }
        })->afterCommit();
    }
}
