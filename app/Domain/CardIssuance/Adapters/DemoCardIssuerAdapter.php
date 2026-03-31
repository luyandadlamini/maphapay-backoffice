<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Adapters;

use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Enums\WalletType;
use App\Domain\CardIssuance\ValueObjects\CardTransaction;
use App\Domain\CardIssuance\ValueObjects\ProvisioningData;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Demo implementation of card issuer for development and testing.
 *
 * Uses SZL (Swazi Lilangeni) currency with realistic local merchants.
 * All state is persisted in cache for the duration of development sessions.
 */
class DemoCardIssuerAdapter implements CardIssuerInterface
{
    private const CACHE_TTL_DAYS = 30;

    /** @var array<array{name: string, mcc: string, amount: int, category: string}> */
    private const DEMO_MERCHANTS = [
        ['name' => 'Pick n Pay Mbabane',       'mcc' => '5411', 'amount' => 28650, 'category' => 'Groceries'],
        ['name' => 'Shoprite Manzini',          'mcc' => '5411', 'amount' => 19200, 'category' => 'Groceries'],
        ['name' => 'Eswatini Mobile (MTN)',     'mcc' => '4814', 'amount' => 10000, 'category' => 'Airtime'],
        ['name' => 'Nandos Swazi Plaza',        'mcc' => '5812', 'amount' => 15500, 'category' => 'Dining'],
        ['name' => 'Game Mbabane',              'mcc' => '5732', 'amount' => 54900, 'category' => 'Electronics'],
        ['name' => 'Engen Petrol Manzini',      'mcc' => '5541', 'amount' => 38000, 'category' => 'Fuel'],
        ['name' => 'Woolworths Swazi Plaza',    'mcc' => '5621', 'amount' => 23400, 'category' => 'Clothing'],
        ['name' => 'Clicks Pharmacy Mbabane',   'mcc' => '5912', 'amount' => 8750,  'category' => 'Pharmacy'],
    ];

    private function demoCurrency(): string
    {
        return (string) config('cardissuance.issuers.demo.currency', 'SZL');
    }

    /** @var array{daily: float, monthly: float, single_transaction: float, atm_withdrawal: float, contactless: float} */
    private const DEFAULT_LIMITS = [
        'daily'              => 2000.00,
        'monthly'            => 10000.00,
        'single_transaction' => 1500.00,
        'atm_withdrawal'     => 1000.00,
        'contactless'        => 500.00,
    ];

    /** @var array{contactless: bool, online_transactions: bool, international: bool, atm_withdrawals: bool} */
    private const DEFAULT_SECURITY = [
        'contactless'        => true,
        'online_transactions' => true,
        'international'      => false,
        'atm_withdrawals'    => true,
    ];

    public function getName(): string
    {
        return 'demo';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createCard(
        string $userId,
        string $cardholderName,
        array $metadata = [],
        ?CardNetwork $network = null,
        ?string $label = null,
    ): VirtualCard {
        $cardToken = 'card_demo_' . bin2hex(random_bytes(16));
        $last4 = (string) random_int(1000, 9999);
        $expiresAt = (new DateTimeImmutable())->modify('+3 years');

        $card = new VirtualCard(
            cardToken: $cardToken,
            last4: $last4,
            network: $network ?? CardNetwork::VISA,
            status: CardStatus::ACTIVE,
            cardholderName: $cardholderName,
            expiresAt: $expiresAt,
            metadata: array_merge($metadata, [
                'user_id'  => $userId,
                'label'    => $label,
                'lifecycle' => $metadata['lifecycle'] ?? 'standard',
            ]),
            label: $label,
        );

        $ttl = now()->addDays(self::CACHE_TTL_DAYS);

        Cache::put("card:{$cardToken}", $card, $ttl);
        Cache::put("card_state:{$cardToken}", [
            'balance'           => 0.00,
            'spending_limit'    => self::DEFAULT_LIMITS['daily'],
            'current_spend'     => 0.00,
            'limits'            => self::DEFAULT_LIMITS,
            'security_settings' => self::DEFAULT_SECURITY,
            'lifecycle'         => $metadata['lifecycle'] ?? 'standard',
            'merchant_binding'  => $metadata['merchant_binding'] ?? null,
        ], $ttl);

        /** @var array<string> $existing */
        $existing = Cache::get("user_cards:{$userId}", []);
        Cache::put("user_cards:{$userId}", array_merge($existing, [$cardToken]), $ttl);

        return $card;
    }

    /**
     * @param array<string> $certificates
     */
    public function getProvisioningData(
        string $cardToken,
        WalletType $walletType,
        string $deviceId,
        array $certificates = []
    ): ProvisioningData {
        return new ProvisioningData(
            cardId: $cardToken,
            walletType: $walletType,
            encryptedPassData: base64_encode("demo_encrypted_pass_data_{$cardToken}"),
            activationData: base64_encode("demo_activation_data_{$deviceId}"),
            ephemeralPublicKey: base64_encode('demo_ephemeral_key_' . bin2hex(random_bytes(32))),
            certificateChain: [
                'demo_certificate_leaf',
                'demo_certificate_intermediate',
                'demo_certificate_root',
            ],
        );
    }

    public function freezeCard(string $cardToken): bool
    {
        $card = $this->getCard($cardToken);
        if ($card === null) {
            return false;
        }

        Cache::put("card:{$cardToken}", new VirtualCard(
            cardToken: $card->cardToken,
            last4: $card->last4,
            network: $card->network,
            status: CardStatus::FROZEN,
            cardholderName: $card->cardholderName,
            expiresAt: $card->expiresAt,
            metadata: $card->metadata,
            label: $card->label,
        ), now()->addDays(self::CACHE_TTL_DAYS));

        return true;
    }

    public function unfreezeCard(string $cardToken): bool
    {
        $card = $this->getCard($cardToken);
        if ($card === null || $card->status !== CardStatus::FROZEN) {
            return false;
        }

        Cache::put("card:{$cardToken}", new VirtualCard(
            cardToken: $card->cardToken,
            last4: $card->last4,
            network: $card->network,
            status: CardStatus::ACTIVE,
            cardholderName: $card->cardholderName,
            expiresAt: $card->expiresAt,
            metadata: $card->metadata,
            label: $card->label,
        ), now()->addDays(self::CACHE_TTL_DAYS));

        return true;
    }

    public function cancelCard(string $cardToken, string $reason): bool
    {
        $card = $this->getCard($cardToken);
        if ($card === null) {
            return false;
        }

        Cache::put("card:{$cardToken}", new VirtualCard(
            cardToken: $card->cardToken,
            last4: $card->last4,
            network: $card->network,
            status: CardStatus::CANCELLED,
            cardholderName: $card->cardholderName,
            expiresAt: $card->expiresAt,
            metadata: array_merge($card->metadata, ['cancellation_reason' => $reason]),
            label: $card->label,
        ), now()->addDays(self::CACHE_TTL_DAYS));

        return true;
    }

    public function getCard(string $cardToken): ?VirtualCard
    {
        return Cache::get("card:{$cardToken}");
    }

    /**
     * @return array<VirtualCard>
     */
    public function listUserCards(string $userId): array
    {
        /** @var array<string> $tokens */
        $tokens = Cache::get("user_cards:{$userId}", []);

        $cards = [];
        foreach ($tokens as $token) {
            $card = $this->getCard($token);
            if ($card !== null && $card->status !== CardStatus::CANCELLED) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * Add funds to the card balance.
     */
    public function addFunds(string $cardToken, float $amountMajorUnit): string
    {
        /** @var array<string, mixed> $state */
        $state = Cache::get("card_state:{$cardToken}", []);
        $current = (float) ($state['balance'] ?? 0.00);
        $state['balance'] = round($current + $amountMajorUnit, 2);

        Cache::put("card_state:{$cardToken}", $state, now()->addDays(self::CACHE_TTL_DAYS));

        return number_format($state['balance'], 2, '.', '');
    }

    /**
     * Get the current card balance.
     */
    public function getBalance(string $cardToken): string
    {
        /** @var array<string, mixed> $state */
        $state = Cache::get("card_state:{$cardToken}", []);

        return number_format((float) ($state['balance'] ?? 0.00), 2, '.', '');
    }

    /**
     * Update per-category spending limits.
     *
     * @param array{daily?: float, monthly?: float, single_transaction?: float, atm_withdrawal?: float, contactless?: float} $limits
     */
    public function updateSpendingLimits(string $cardToken, array $limits): bool
    {
        /** @var array<string, mixed> $state */
        $state = Cache::get("card_state:{$cardToken}", []);

        /** @var array<string, float> $existing */
        $existing = $state['limits'] ?? self::DEFAULT_LIMITS;
        $state['limits'] = array_merge($existing, $limits);
        $state['spending_limit'] = $state['limits']['daily'];

        Cache::put("card_state:{$cardToken}", $state, now()->addDays(self::CACHE_TTL_DAYS));

        return true;
    }

    /**
     * Update security settings (channel toggles).
     *
     * @param array{contactless?: bool, online_transactions?: bool, international?: bool, atm_withdrawals?: bool} $settings
     */
    public function updateSecuritySettings(string $cardToken, array $settings): bool
    {
        /** @var array<string, mixed> $state */
        $state = Cache::get("card_state:{$cardToken}", []);

        /** @var array<string, bool> $existing */
        $existing = $state['security_settings'] ?? self::DEFAULT_SECURITY;
        $state['security_settings'] = array_merge($existing, $settings);

        Cache::put("card_state:{$cardToken}", $state, now()->addDays(self::CACHE_TTL_DAYS));

        return true;
    }

    /**
     * Get the card's financial and settings state.
     *
     * @return array<string, mixed>
     */
    public function getCardState(string $cardToken): array
    {
        /** @var array<string, mixed> $state */
        $state = Cache::get("card_state:{$cardToken}", []);

        return array_merge([
            'balance'           => 0.00,
            'spending_limit'    => self::DEFAULT_LIMITS['daily'],
            'current_spend'     => 0.00,
            'limits'            => self::DEFAULT_LIMITS,
            'security_settings' => self::DEFAULT_SECURITY,
            'lifecycle'         => 'standard',
            'merchant_binding'  => null,
        ], $state);
    }

    /**
     * Generate deterministic SZL demo transactions seeded by card token.
     *
     * @return array{transactions: array<CardTransaction>, next_cursor: string|null}
     */
    public function getTransactions(string $cardToken, int $limit = 20, ?string $cursor = null): array
    {
        $startIndex = $cursor !== null ? (int) $cursor : 0;
        $merchants = self::DEMO_MERCHANTS;
        $statuses = ['settled', 'settled', 'pending', 'settled', 'settled', 'settled', 'settled', 'settled'];
        $transactions = [];
        $baseTime = new DateTimeImmutable('2026-03-25T10:00:00+02:00');

        for ($i = $startIndex; $i < min($startIndex + $limit, count($merchants)); $i++) {
            $merchant = $merchants[$i];
            $seed = hash('sha256', $cardToken . ':' . $i);

            $transactions[] = new CardTransaction(
                transactionId: 'txn_demo_' . substr($seed, 0, 16),
                cardToken: $cardToken,
                merchantName: $merchant['name'],
                merchantCategory: $merchant['mcc'],
                amountCents: $merchant['amount'],
                currency: $this->demoCurrency(),
                status: $statuses[$i] ?? 'settled',
                timestamp: $baseTime->modify('-' . ($i * 6) . ' hours'),
            );
        }

        $hasMore = ($startIndex + $limit) < count($merchants);

        return [
            'transactions' => $transactions,
            'next_cursor'  => $hasMore ? (string) ($startIndex + $limit) : null,
        ];
    }
}
