<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Handlers;

use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\CardTransaction as CardTransactionValueObject;
use App\Domain\CardSubscriptions\Http\Resources\CardDisputeResource;
use App\Domain\CardSubscriptions\Http\Resources\CardResource;
use App\Domain\CardSubscriptions\Http\Resources\CardSubscriptionResource;
use App\Domain\CardSubscriptions\Http\Resources\PhysicalCardOrderResource;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardDisputeService;
use App\Domain\CardSubscriptions\Services\CardLifecycleService;
use App\Domain\CardSubscriptions\Services\CardRevealService;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Domain\CardSubscriptions\Services\PhysicalCardOrderService;
use App\Domain\CardSubscriptions\ValueObjects\ActivateInput;
use App\Domain\CardSubscriptions\ValueObjects\CardControlsInput;
use App\Domain\CardSubscriptions\ValueObjects\CreateVirtualCardInput;
use App\Domain\CardSubscriptions\ValueObjects\DisputeInput;
use App\Domain\CardSubscriptions\ValueObjects\PhysicalCardDeliveryAddress;
use App\Domain\CardSubscriptions\ValueObjects\ReplacementReason;
use App\Domain\CardSubscriptions\ValueObjects\RequestPhysicalCardInput;
use App\Models\User;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Finalizes card monetisation operations after PIN/OTP verification.
 *
 * Payload shape (initiation step sets these):
 * - `operation` (string): discriminator (freeze, subscribe, physical_cancel, …).
 * - `user_id` (int): owning user (redundant with AuthorizedTransaction.user_id; used for defence in depth).
 * - Operation-specific keys (see switch).
 */
class CardProductAuthorizedHandler implements AuthorizedTransactionHandlerInterface
{
    public function __construct(
        private readonly CardLifecycleService $lifecycleService,
        private readonly CardSubscriptionService $subscriptionService,
        private readonly CardRevealService $revealService,
        private readonly CardDisputeService $disputeService,
        private readonly PhysicalCardOrderService $physicalOrderService,
        private readonly CardAuditService $auditService,
    ) {
    }

    public function handle(AuthorizedTransaction $transaction): array
    {
        $payload = $this->normalizePayload($transaction->payload);
        $operation = $payload['operation'] ?? null;
        if (! is_string($operation) || $operation === '') {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: missing operation.');
        }

        $user = $transaction->user;
        if (! $user instanceof User) {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: transaction user missing.');
        }

        if ((int) ($payload['user_id'] ?? 0) !== (int) $user->id) {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: user_id mismatch.');
        }

        return match ($operation) {
            'freeze'              => $this->handleFreeze($user, $payload),
            'unfreeze'            => $this->handleUnfreeze($user, $payload),
            'cancel'              => $this->handleCancel($user, $payload),
            'replace'             => $this->handleReplace($user, $payload),
            'create_virtual'      => $this->handleCreateVirtual($user, $payload),
            'update_controls'     => $this->handleUpdateControls($user, $payload),
            'subscribe'           => $this->handleSubscribe($user, $payload),
            'upgrade'             => $this->handleUpgrade($user, $payload),
            'downgrade'           => $this->handleDowngrade($user, $payload),
            'cancel_subscription' => $this->handleCancelSubscription($user, $payload),
            'reveal_mint'         => $this->handleRevealMint($user, $payload),
            'dispute_transaction' => $this->handleDispute($user, $payload),
            'physical_request'    => $this->handlePhysicalRequest($user, $payload),
            'physical_activate'   => $this->handlePhysicalActivate($user, $payload),
            'physical_cancel'     => $this->handlePhysicalCancel($user, $payload),
            default               => throw new InvalidArgumentException("Unknown card_product operation: {$operation}"),
        };
    }

    /**
     * @param  array<string, mixed>|object|string|null  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $payload): array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_object($payload)) {
            return (array) $payload;
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleFreeze(User $user, array $payload): array
    {
        $card = $this->resolveOwnedCard($user, (string) ($payload['card_id'] ?? ''));
        $reason = (string) ($payload['reason'] ?? 'user_initiated');
        $card = $this->lifecycleService->freezeCard($user, $card, $reason);

        return $this->cardEnvelope($card);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleUnfreeze(User $user, array $payload): array
    {
        $card = $this->resolveOwnedCard($user, (string) ($payload['card_id'] ?? ''));
        $card = $this->lifecycleService->unfreezeCard($user, $card);

        return $this->cardEnvelope($card);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleCancel(User $user, array $payload): array
    {
        $card = $this->resolveOwnedCard($user, (string) ($payload['card_id'] ?? ''));
        $card = $this->lifecycleService->cancelCard($user, $card, 'user_requested');

        return $this->cardEnvelope($card);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleReplace(User $user, array $payload): array
    {
        $card = $this->resolveOwnedCard($user, (string) ($payload['card_id'] ?? ''));
        $reason = ReplacementReason::tryFrom((string) ($payload['reason'] ?? '')) ?? ReplacementReason::DAMAGED;
        $newCard = $this->lifecycleService->replaceCard($user, $card, $reason);

        return $this->cardEnvelope($newCard);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleCreateVirtual(User $user, array $payload): array
    {
        $subscription = $this->resolveOwnedSubscription($user, (string) ($payload['subscription_id'] ?? ''));
        $input = $this->buildCreateVirtualInput($payload['create_virtual'] ?? []);
        $card = $this->lifecycleService->createVirtualCard($user, $subscription, $input);

        return $this->cardEnvelope($card);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleUpdateControls(User $user, array $payload): array
    {
        $card = $this->resolveOwnedCard($user, (string) ($payload['card_id'] ?? ''));
        $patch = $payload['controls_patch'] ?? [];
        if (! is_array($patch)) {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: controls_patch must be an array.');
        }
        $input = CardControlsInput::fromArray([
            'limits' => [
                'per_transaction_cents' => $patch['per_transaction_limit'] ?? $patch['per_transaction_cents'] ?? null,
                'daily_cents'           => $patch['daily_limit'] ?? $patch['daily_cents'] ?? null,
                'monthly_cents'         => $patch['monthly_limit'] ?? $patch['monthly_cents'] ?? null,
            ],
            'online_enabled'        => (bool) ($patch['online_enabled'] ?? true),
            'international_enabled' => (bool) ($patch['international_enabled'] ?? false),
            'atm_enabled'           => (bool) ($patch['atm_enabled'] ?? false),
            'contactless_enabled'   => (bool) ($patch['contactless_enabled'] ?? true),
            'blocked_mcc_groups'    => is_array($patch['blocked_mcc_groups'] ?? null) ? $patch['blocked_mcc_groups'] : [],
        ]);
        $card = $this->lifecycleService->updateControls($user, $card, $input);

        return $this->cardEnvelope($card);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleSubscribe(User $user, array $payload): array
    {
        $planCode = (string) ($payload['plan_code'] ?? '');
        if ($planCode === '') {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: plan_code required.');
        }
        $subscriberRef = $payload['subscriber_user_id'] ?? null;
        $subscriber = $user;
        if (is_string($subscriberRef) && $subscriberRef !== '') {
            $subscriber = User::query()->where('uuid', $subscriberRef)->firstOrFail();
        }
        $payer = $user;
        $subscription = $this->subscriptionService->subscribe(
            subscriber: $subscriber,
            planCode: $planCode,
            payer: $payer,
            minorRequestId: isset($payload['minor_card_request_id']) ? (string) $payload['minor_card_request_id'] : null,
        );

        return $this->subscriptionEnvelope($subscription);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleUpgrade(User $user, array $payload): array
    {
        $planCode = (string) ($payload['plan_code'] ?? '');
        if ($planCode === '') {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: plan_code required.');
        }
        $subscription = $this->subscriptionService->upgrade($user, $planCode);

        return $this->subscriptionEnvelope($subscription);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleDowngrade(User $user, array $payload): array
    {
        $planCode = (string) ($payload['plan_code'] ?? '');
        if ($planCode === '') {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: plan_code required.');
        }
        $force = (bool) ($payload['force'] ?? false);
        $subscription = $this->subscriptionService->downgrade($user, $planCode, $force);

        return $this->subscriptionEnvelope($subscription);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleCancelSubscription(User $user, array $payload): array
    {
        $subscription = $this->subscriptionService->cancel($user);

        return $this->subscriptionEnvelope($subscription);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleRevealMint(User $user, array $payload): array
    {
        $card = $this->resolveOwnedCard($user, (string) ($payload['card_id'] ?? ''));
        $result = $this->revealService->mintRevealUrl($user, $card);

        $this->auditService->recordCardEvent('reveal_requested', $card, null, [
            'expires_at' => $result->expiresAt->format('c'),
        ]);

        return [
            'reveal_url'  => $result->url,
            'expires_at'  => $result->expiresAt->format('c'),
            'ttl_seconds' => max(0, now()->diffInSeconds($result->expiresAt, false)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleDispute(User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? '');
        $disputePayload = $payload['dispute'] ?? [];
        if (! is_array($disputePayload)) {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: dispute payload invalid.');
        }
        $transaction = new CardTransactionValueObject(
            transactionId: $transactionId,
            cardToken: $cardId,
            merchantName: 'Mock Merchant',
            merchantCategory: 'Mock Category',
            amountCents: 1000,
            currency: 'ZAR',
            status: 'settled',
            timestamp: new DateTimeImmutable()
        );
        $input = new DisputeInput(
            reason: (string) ($disputePayload['reason'] ?? ''),
            description: (string) ($disputePayload['description'] ?? ''),
            amountCents: (int) round(((float) ($disputePayload['disputed_amount'] ?? 0)) * 100)
        );
        $dispute = $this->disputeService->open($user, $transaction, $input);

        return [
            'dispute' => (new CardDisputeResource($dispute))->toArray(request()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handlePhysicalRequest(User $user, array $payload): array
    {
        $subscription = $this->subscriptionService->getCurrent($user);
        if (! $subscription instanceof CardSubscription) {
            abort(403, 'Active subscription required.');
        }
        $req = $payload['physical_request'] ?? [];
        if (! is_array($req)) {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: physical_request invalid.');
        }
        $deliveryMethod = (string) ($req['delivery_method'] ?? '');
        $addressInput = null;
        if ($deliveryMethod === 'courier') {
            $address = $req['delivery_address'] ?? [];
            if (! is_array($address)) {
                throw new InvalidArgumentException('CardProductAuthorizedHandler: delivery_address invalid.');
            }
            $addressInput = new PhysicalCardDeliveryAddress(
                recipientName: $user->name,
                phone: (string) ($address['phone_number'] ?? ''),
                addressLine1: (string) ($address['line1'] ?? ''),
                addressLine2: isset($address['line2']) ? (string) $address['line2'] : null,
                city: (string) ($address['city'] ?? ''),
                region: (string) ($address['region'] ?? 'Unknown'),
                countryCode: (string) ($address['country'] ?? '')
            );
        }
        $input = new RequestPhysicalCardInput(
            deliveryMethod: $deliveryMethod,
            deliveryAddress: $addressInput,
            collectionPointId: isset($req['collection_point_id']) ? (string) $req['collection_point_id'] : null
        );
        $order = $this->physicalOrderService->request($user, $subscription, $input);

        return [
            'order' => (new PhysicalCardOrderResource($order))->toArray(request()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handlePhysicalActivate(User $user, array $payload): array
    {
        $subscription = $this->subscriptionService->getCurrent($user);
        if (! $subscription instanceof CardSubscription) {
            abort(403, 'Active subscription required.');
        }
        $orderId = (string) ($payload['order_id'] ?? '');
        $order = $subscription->orders()->where('id', $orderId)->firstOrFail();
        $act = $payload['activate'] ?? [];
        if (! is_array($act)) {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: activate payload invalid.');
        }
        $input = new ActivateInput(
            activationCode: (string) ($act['activation_code'] ?? ''),
            metadata: ['pin' => (string) ($act['pin'] ?? '')]
        );
        $this->physicalOrderService->activate($user, $order, $input);
        $order->refresh();

        return [
            'order' => (new PhysicalCardOrderResource($order))->toArray(request()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handlePhysicalCancel(User $user, array $payload): array
    {
        $subscription = $this->subscriptionService->getCurrent($user);
        if (! $subscription instanceof CardSubscription) {
            abort(403, 'Active subscription required.');
        }
        $orderId = (string) ($payload['order_id'] ?? '');
        $reason = (string) ($payload['cancellation_reason'] ?? 'user_requested');
        $order = $subscription->orders()->where('id', $orderId)->firstOrFail();
        $order = $this->physicalOrderService->cancel($user, $order, $reason);

        return [
            'order' => (new PhysicalCardOrderResource($order))->toArray(request()),
        ];
    }

    private function resolveOwnedCard(User $user, string $cardId): Card
    {
        if ($cardId === '') {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: card_id required.');
        }

        return Card::query()
            ->whereKey($cardId)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    private function resolveOwnedSubscription(User $user, string $subscriptionId): CardSubscription
    {
        if ($subscriptionId === '') {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: subscription_id required.');
        }

        return CardSubscription::query()
            ->whereKey($subscriptionId)
            ->where('subscriber_user_id', $user->id)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildCreateVirtualInput(mixed $raw): CreateVirtualCardInput
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: create_virtual payload must be an array.');
        }
        $controls = $raw['controls'] ?? null;
        if (! is_array($controls)) {
            throw new InvalidArgumentException('CardProductAuthorizedHandler: create_virtual.controls required.');
        }

        return new CreateVirtualCardInput(
            controls: CardControlsInput::fromArray([
                'limits' => [
                    'per_transaction_cents' => (int) round(((float) ($controls['per_transaction_limit'] ?? 0)) * 100),
                    'daily_cents'           => (int) round(((float) ($controls['daily_limit'] ?? 0)) * 100),
                    'monthly_cents'         => (int) round(((float) ($controls['monthly_limit'] ?? 0)) * 100),
                ],
                'online_enabled'        => (bool) ($controls['online_enabled'] ?? true),
                'international_enabled' => (bool) ($controls['international_enabled'] ?? false),
                'atm_enabled'           => (bool) ($controls['atm_enabled'] ?? false),
                'contactless_enabled'   => (bool) ($controls['contactless_enabled'] ?? true),
                'blocked_mcc_groups'    => array_values($controls['blocked_mcc_groups'] ?? []),
            ]),
            label: isset($raw['nickname']) ? (string) $raw['nickname'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cardEnvelope(Card $card): array
    {
        return [
            'card' => (new CardResource($card))->toArray(request()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionEnvelope(CardSubscription $subscription): array
    {
        return [
            'subscription' => (new CardSubscriptionResource($subscription->load('plan')))->toArray(request()),
        ];
    }
}
