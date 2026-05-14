<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Adapters;

use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Enums\WalletType;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\CardTransaction;
use App\Domain\CardIssuance\ValueObjects\ProvisioningData;
use App\Domain\CardIssuance\ValueObjects\RevealUrlResult;
use App\Domain\CardIssuance\ValueObjects\StripeUsdToSzlConverter;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;
use App\Models\User;
use DateTimeImmutable;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeCardIssuerAdapter implements CardIssuerInterface
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeUsdToSzlConverter $converter,
        private readonly string $webhookSecret,
    ) {
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function createCard(
        string $userId,
        string $cardholderName,
        array $metadata = [],
        ?CardNetwork $network = null,
        ?string $label = null,
    ): VirtualCard {
        $user = $this->findUser($userId);
        $cardholderId = $this->ensureCardholder($user, $cardholderName);

        $card = $this->stripe->issuing->cards->create([
            'cardholder' => $cardholderId,
            'currency'   => 'usd',
            'type'       => 'virtual',
            'status'     => 'active',
            'metadata'   => array_merge($metadata, [
                'maphapay_user_id' => (string) $user->id,
                'label'            => $label ?? '',
            ]),
        ]);

        return new VirtualCard(
            cardToken: (string) $card->id,
            last4: (string) $card->last4,
            network: $this->mapNetwork((string) ($card->brand ?? 'visa')),
            status: $this->mapStatus((string) ($card->status ?? 'active')),
            cardholderName: $cardholderName,
            expiresAt: new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $card->exp_year, (int) $card->exp_month)),
            metadata: method_exists($card->metadata, 'toArray') ? $card->metadata->toArray() : (array) $card->metadata,
            label: $label,
        );
    }

    public function getProvisioningData(
        string $cardToken,
        WalletType $walletType,
        string $deviceId,
        array $certificates = []
    ): ProvisioningData {
        throw new RuntimeException('Apple Pay / Google Pay provisioning is out of scope for the Stripe adapter.');
    }

    public function freezeCard(string $cardToken): bool
    {
        $this->stripe->issuing->cards->update($cardToken, ['status' => 'inactive']);

        return true;
    }

    public function unfreezeCard(string $cardToken): bool
    {
        $this->stripe->issuing->cards->update($cardToken, ['status' => 'active']);

        return true;
    }

    public function cancelCard(string $cardToken, string $reason): bool
    {
        $this->stripe->issuing->cards->update($cardToken, [
            'status'   => 'canceled',
            'metadata' => ['cancel_reason' => $reason],
        ]);

        return true;
    }

    public function getCard(string $cardToken): ?VirtualCard
    {
        $card = Card::query()
            ->where('issuer_card_token', $cardToken)
            ->first();

        if (! $card instanceof Card) {
            return null;
        }

        return $this->cardModelToVirtualCard($card);
    }

    public function listUserCards(string $userId): array
    {
        $user = User::query()
            ->where('uuid', $userId)
            ->orWhere('id', $userId)
            ->first();

        if (! $user instanceof User) {
            return [];
        }

        return Card::query()
            ->where('user_id', $user->id)
            ->where('issuer', $this->getName())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Card $card): VirtualCard => $this->cardModelToVirtualCard($card))
            ->all();
    }

    public function getTransactions(string $cardToken, int $limit = 20, ?string $cursor = null): array
    {
        $card = Card::query()->where('issuer_card_token', $cardToken)->first();
        if (! $card instanceof Card) {
            return ['transactions' => [], 'next_cursor' => null];
        }

        $query = \App\Domain\CardSubscriptions\Models\CardTransaction::query()
            ->where('card_id', $card->id)
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->get();
        $items = $rows->take($limit);

        return [
            'transactions' => $items->map(function (\App\Domain\CardSubscriptions\Models\CardTransaction $transaction) use ($cardToken): CardTransaction {
                $billingAmount = $transaction->billing_amount ?? number_format($transaction->amount_cents / 100, 2, '.', '');

                return new CardTransaction(
                    transactionId: (string) $transaction->id,
                    cardToken: $cardToken,
                    merchantName: $transaction->merchant_name,
                    merchantCategory: $transaction->merchant_category,
                    amountCents: (int) round(((float) $billingAmount) * 100),
                    currency: $this->converter->billingCurrency(),
                    status: $transaction->status,
                    timestamp: new DateTimeImmutable(($transaction->created_at ?? now())->toIso8601String()),
                );
            })->all(),
            'next_cursor' => $rows->count() > $limit ? (string) $items->last()?->id : null,
        ];
    }

    public function addFunds(string $cardToken, float $amountMajorUnit): string
    {
        return $this->getBalance($cardToken);
    }

    public function getBalance(string $cardToken): string
    {
        return '0.00';
    }

    public function updateSpendingLimits(string $cardToken, array $limits): bool
    {
        return true;
    }

    public function updateSecuritySettings(string $cardToken, array $settings): bool
    {
        return true;
    }

    public function generateRevealUrl(string $issuerCardToken, int $ttlSeconds): RevealUrlResult
    {
        $expiresAt = now()->addSeconds($ttlSeconds);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.v1.cards.stripe.reveal',
            $expiresAt,
            ['card' => $issuerCardToken]
        );

        return new RevealUrlResult(
            url: $url,
            expiresAt: $expiresAt->toDateTimeImmutable(),
            ttlSeconds: $ttlSeconds,
        );
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        try {
            Webhook::constructEvent($rawBody, $signature, $this->webhookSecret);

            return true;
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return false;
        }
    }

    private function ensureCardholder(User $user, string $cardholderName): string
    {
        if (is_string($user->stripe_cardholder_id) && $user->stripe_cardholder_id !== '') {
            return $user->stripe_cardholder_id;
        }

        $cardholder = $this->stripe->issuing->cardholders->create([
            'type'         => 'individual',
            'name'         => $cardholderName,
            'email'        => $user->email ?? 'noreply@maphapay.test',
            'phone_number' => $this->normalizePhoneNumber($user),
            'billing'      => [
                'address' => $this->defaultBillingAddress(),
            ],
            'individual' => $this->individualDetails($cardholderName),
            'metadata' => [
                'maphapay_user_id' => (string) $user->id,
            ],
        ]);

        $user->update(['stripe_cardholder_id' => (string) $cardholder->id]);

        return (string) $cardholder->id;
    }

    private function findUser(string $userId): User
    {
        return User::query()
            ->where('uuid', $userId)
            ->orWhere('id', $userId)
            ->firstOrFail();
    }

    private function normalizePhoneNumber(User $user): string
    {
        $mobile = (string) preg_replace('/\s+/', '', (string) ($user->mobile ?? ''));
        if (str_starts_with($mobile, '+')) {
            return $mobile;
        }

        return '+26878000000';
    }

    /**
     * @return array{line1: string, city: string, state: string, postal_code: string, country: string}
     */
    private function defaultBillingAddress(): array
    {
        return [
            'line1'       => '354 Oyster Point Blvd',
            'city'        => 'South San Francisco',
            'state'       => 'CA',
            'postal_code' => '94080',
            'country'     => 'US',
        ];
    }

    /**
     * @return array{first_name: string, last_name: string, card_issuing: array{user_terms_acceptance: array{date: int, ip: string}}}
     */
    private function individualDetails(string $cardholderName): array
    {
        $nameParts = preg_split('/\s+/', trim($cardholderName)) ?: [];
        $firstName = (string) ($nameParts[0] ?? 'Test');
        $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'User';

        return [
            'first_name' => $firstName !== '' ? $firstName : 'Test',
            'last_name' => $lastName !== '' ? $lastName : 'User',
            'card_issuing' => [
                'user_terms_acceptance' => [
                    'date' => time(),
                    'ip' => '127.0.0.1',
                ],
            ],
        ];
    }

    private function cardModelToVirtualCard(Card $card): VirtualCard
    {
        return new VirtualCard(
            cardToken: $card->issuer_card_token,
            last4: $card->last4,
            network: $this->mapNetwork($card->network),
            status: $this->mapStatus($card->status),
            cardholderName: $card->cardholder?->getFullName() ?? '',
            expiresAt: $card->expires_at
                ? DateTimeImmutable::createFromMutable($card->expires_at->toDateTime())
                : new DateTimeImmutable('+3 years'),
            metadata: $card->metadata ?? [],
            label: $card->label,
        );
    }

    private function mapNetwork(string $network): CardNetwork
    {
        return match (strtolower($network)) {
            'mastercard' => CardNetwork::MASTERCARD,
            default      => CardNetwork::VISA,
        };
    }

    private function mapStatus(string $status): CardStatus
    {
        return match (strtolower($status)) {
            'active' => CardStatus::ACTIVE,
            'inactive', 'frozen', 'frozen_by_user', 'frozen_by_admin', 'suspended' => CardStatus::FROZEN,
            'canceled', 'cancelled' => CardStatus::CANCELLED,
            'expired' => CardStatus::EXPIRED,
            default => CardStatus::PENDING,
        };
    }
}
