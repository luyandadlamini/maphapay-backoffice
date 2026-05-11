<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Domain\CardSubscriptions\Enums\CardErrorCode;
use App\Domain\CardSubscriptions\Exceptions\EntitlementDeniedException;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\ValueObjects\CardControlsInput;
use App\Domain\CardSubscriptions\ValueObjects\CreateVirtualCardInput;
use App\Domain\CardSubscriptions\ValueObjects\ReplacementReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CardLifecycleService
{
    public function __construct(
        private readonly CardEntitlementService $entitlements,
        private readonly CardProvisioningService $provisioning,
    ) {
    }

    public function createVirtualCard(User $user, CardSubscription $subscription, CreateVirtualCardInput $input): Card
    {
        $decision = $this->entitlements->canCreateVirtualCard($user);

        if (! $decision->allowed) {
            throw new EntitlementDeniedException(
                $decision->code ?? CardErrorCode::SUBSCRIPTION_REQUIRED,
                $decision->message ?? 'Card creation denied by entitlement policy.'
            );
        }

        return DB::transaction(function () use ($user, $subscription, $input) {
            // 1. Provision via issuer
            $virtualCard = $this->provisioning->createCard(
                userId: (string) $user->id,
                cardholderName: $user->name,
                metadata: $input->metadata,
                label: $input->label
            );

            // 2. PersistCardRecord already called in createCard, so find it
            $card = Card::where('issuer_card_token', $virtualCard->cardToken)->firstOrFail();

            // 3. Enrich with subscription and controls
            $card->update([
                'card_subscription_id'  => $subscription->id,
                'kind'                  => 'virtual',
                'per_transaction_limit' => $input->controls->limits->perTransactionCents / 100,
                'daily_limit'           => $input->controls->limits->dailyCents / 100,
                'monthly_limit'         => $input->controls->limits->monthlyCents / 100,
                'online_enabled'        => $input->controls->onlineEnabled,
                'international_enabled' => $input->controls->internationalEnabled,
                'atm_enabled'           => $input->controls->atmEnabled,
                'contactless_enabled'   => $input->controls->contactlessEnabled,
                'blocked_mcc_groups'    => $input->controls->blockedMccGroups,
            ]);

            return $card;
        });
    }

    public function freezeCard(User $actor, Card $card, string $reason): Card
    {
        $this->provisioning->freezeCard($card->issuer_card_token);

        $card->update([
            'status'    => 'frozen',
            'frozen_at' => now(),
        ]);

        return $card;
    }

    public function unfreezeCard(User $actor, Card $card): Card
    {
        $this->provisioning->unfreezeCard($card->issuer_card_token);

        $card->update([
            'status'    => 'active',
            'frozen_at' => null,
        ]);

        return $card;
    }

    public function cancelCard(User $actor, Card $card, string $reason): Card
    {
        $this->provisioning->cancelCard($card->issuer_card_token, $reason);

        $card->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return $card;
    }

    public function replaceCard(User $actor, Card $card, ReplacementReason $reason): Card
    {
        // Cancel old card
        $this->cancelCard($actor, $card, "replacement_{$reason->value}");

        // Create new one (simplified for now, using old label)
        $input = new CreateVirtualCardInput(
            controls: CardControlsInput::fromArray([
                'limits' => [
                    'per_transaction_cents' => (int) (($card->per_transaction_limit ?? 0) * 100),
                    'daily_cents'           => (int) (($card->daily_limit ?? 0) * 100),
                    'monthly_cents'         => (int) (($card->monthly_limit ?? 0) * 100),
                ],
                'online_enabled'        => $card->online_enabled,
                'international_enabled' => $card->international_enabled,
                'atm_enabled'           => $card->atm_enabled,
                'contactless_enabled'   => $card->contactless_enabled,
                'blocked_mcc_groups'    => $card->blocked_mcc_groups ?? [],
            ]),
            label: $card->label
        );

        $subscription = CardSubscription::findOrFail($card->card_subscription_id);

        return $this->createVirtualCard($actor, $subscription, $input);
    }

    public function updateControls(User $actor, Card $card, CardControlsInput $controls): Card
    {
        // Update issuer if they support it
        $this->provisioning->updateSpendingLimits($card->issuer_card_token, [
            'per_transaction' => (float) ($controls->limits->perTransactionCents / 100),
            'daily'           => (float) ($controls->limits->dailyCents / 100),
            'monthly'         => (float) ($controls->limits->monthlyCents / 100),
        ]);

        $this->provisioning->updateSecuritySettings($card->issuer_card_token, [
            'online'        => $controls->onlineEnabled,
            'international' => $controls->internationalEnabled,
            'atm'           => $controls->atmEnabled,
            'contactless'   => $controls->contactlessEnabled,
        ]);

        // Sync local record
        $card->update([
            'per_transaction_limit' => $controls->limits->perTransactionCents / 100,
            'daily_limit'           => $controls->limits->dailyCents / 100,
            'monthly_limit'         => $controls->limits->monthlyCents / 100,
            'online_enabled'        => $controls->onlineEnabled,
            'international_enabled' => $controls->internationalEnabled,
            'atm_enabled'           => $controls->atmEnabled,
            'contactless_enabled'   => $controls->contactlessEnabled,
            'blocked_mcc_groups'    => $controls->blockedMccGroups,
        ]);

        return $card;
    }

    public function adminFreeze(User $admin, Card $card, string $reason): Card
    {
        $this->provisioning->freezeCard($card->issuer_card_token);

        $card->update([
            'status'    => 'frozen_by_admin',
            'frozen_at' => now(),
        ]);

        return $card;
    }

    public function adminUnfreeze(User $admin, Card $card, string $reason): Card
    {
        if ($card->status !== 'frozen_by_admin') {
            throw new InvalidArgumentException('Card must be in frozen_by_admin state for admin unfreeze.');
        }

        return $this->unfreezeCard($admin, $card);
    }
}
