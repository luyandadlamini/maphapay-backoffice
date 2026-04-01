<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Account\DataObjects\Account as AccountData;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\AccountService;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Domain\Rewards\Services\RewardsService;
use App\Models\User;

class DefaultUserResourceProvisioningService
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly RewardsService $rewardsService,
        private readonly CardProvisioningService $cardProvisioningService,
    ) {
    }

    /**
     * Ensure the default wallet account, rewards profile, and default MCard exist.
     */
    public function ensureForUser(User $user): void
    {
        $this->ensureWalletAccount($user);
        $this->ensureRewardsProfile($user);
        $this->ensureDefaultMcard($user);
    }

    private function ensureWalletAccount(User $user): void
    {
        $account = Account::query()
            ->where('user_uuid', $user->uuid)
            ->orderBy('id')
            ->first();

        if ($account !== null) {
            if (! Account::isValidAccountNumberFormat($account->account_number)) {
                $account->account_number = Account::generateAccountNumber();
                $account->save();
            }
            return;
        }

        $this->accountService->createDirect(new AccountData(
            name: 'Maphapay Wallet',
            userUuid: $user->uuid,
        ));
    }

    private function ensureRewardsProfile(User $user): void
    {
        $this->rewardsService->getProfile($user);
    }

    private function ensureDefaultMcard(User $user): void
    {
        $hasPersistedCard = Card::query()
            ->where('user_id', $user->id)
            ->exists();

        if ($hasPersistedCard) {
            return;
        }

        $existingCards = $this->cardProvisioningService->listUserCards($user->uuid);
        if (count($existingCards) > 0) {
            return;
        }

        $cardholderName = trim((string) $user->name);
        if ($cardholderName === '') {
            $cardholderName = 'Card Holder';
        }

        $this->cardProvisioningService->createCard(
            userId: $user->uuid,
            cardholderName: $cardholderName,
            metadata: ['is_default' => true],
        );
    }
}
