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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RainCardIssuerAdapter implements CardIssuerInterface
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    private readonly string $programId;

    /**
     * @param array{base_url?: string, api_key?: string, program_id?: string} $config
     */
    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->programId = (string) ($config['program_id'] ?? '');

        if ($this->baseUrl === '' || $this->apiKey === '' || $this->programId === '') {
            throw new RuntimeException('Rain issuer requires base_url, api_key and program_id.');
        }
    }

    public function getName(): string
    {
        return 'rain';
    }

    public function createCard(
        string $userId,
        string $cardholderName,
        array $metadata = [],
        ?CardNetwork $network = null,
        ?string $label = null,
    ): VirtualCard {
        $response = $this->client()->post('/cards', [
            'program_id'      => $this->programId,
            'user_id'         => $userId,
            'cardholder_name' => $cardholderName,
            'network'         => ($network ?? CardNetwork::VISA)->value,
            'label'           => $label,
            'metadata'        => $metadata,
        ])->throw();

        /** @var array<string, mixed> $card */
        $card = (array) ($response->json('data') ?? []);

        return $this->mapVirtualCard($card, $label, $metadata);
    }

    public function getProvisioningData(
        string $cardToken,
        WalletType $walletType,
        string $deviceId,
        array $certificates = []
    ): ProvisioningData {
        $response = $this->client()->post("/cards/{$cardToken}/provision", [
            'wallet_type'  => $walletType->value,
            'device_id'    => $deviceId,
            'certificates' => $certificates,
        ])->throw();

        /** @var array<string, mixed> $data */
        $data = (array) ($response->json('data') ?? []);

        return new ProvisioningData(
            cardId: $cardToken,
            walletType: $walletType,
            encryptedPassData: (string) ($data['encrypted_pass_data'] ?? ''),
            activationData: (string) ($data['activation_data'] ?? ''),
            ephemeralPublicKey: (string) ($data['ephemeral_public_key'] ?? ''),
            certificateChain: array_values((array) ($data['certificate_chain'] ?? [])),
        );
    }

    public function freezeCard(string $cardToken): bool
    {
        return $this->client()->patch("/cards/{$cardToken}", ['status' => 'frozen'])->successful();
    }

    public function unfreezeCard(string $cardToken): bool
    {
        return $this->client()->patch("/cards/{$cardToken}", ['status' => 'active'])->successful();
    }

    public function cancelCard(string $cardToken, string $reason): bool
    {
        return $this->client()->post("/cards/{$cardToken}/cancel", ['reason' => $reason])->successful();
    }

    public function getCard(string $cardToken): ?VirtualCard
    {
        $response = $this->client()->get("/cards/{$cardToken}");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        /** @var array<string, mixed> $card */
        $card = (array) ($response->json('data') ?? []);

        return $this->mapVirtualCard($card);
    }

    public function listUserCards(string $userId): array
    {
        $response = $this->client()->get('/cards', ['user_id' => $userId])->throw();

        return collect((array) ($response->json('data') ?? []))
            ->map(fn ($card) => $this->mapVirtualCard((array) $card))
            ->filter(fn (VirtualCard $card) => $card->status !== CardStatus::CANCELLED)
            ->values()
            ->all();
    }

    public function getTransactions(string $cardToken, int $limit = 20, ?string $cursor = null): array
    {
        $query = ['card_id' => $cardToken, 'limit' => $limit];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        $response = $this->client()->get('/transactions', $query)->throw();
        $data = collect((array) ($response->json('data') ?? []))
            ->map(function ($transaction) use ($cardToken): CardTransaction {
                /** @var array<string, mixed> $transaction */
                $transaction = (array) $transaction;

                return new CardTransaction(
                    transactionId: (string) ($transaction['id'] ?? ''),
                    cardToken: $cardToken,
                    merchantName: (string) ($transaction['merchant_name'] ?? 'Unknown'),
                    merchantCategory: (string) ($transaction['merchant_category_code'] ?? ''),
                    amountCents: (int) ($transaction['amount'] ?? 0),
                    currency: (string) ($transaction['currency'] ?? 'USD'),
                    status: $this->mapTransactionStatus((string) ($transaction['status'] ?? 'pending')),
                    timestamp: new DateTimeImmutable((string) ($transaction['created_at'] ?? 'now')),
                );
            })
            ->values()
            ->all();

        $nextCursor = $response->json('has_more') === true && ! empty($data)
            ? end($data)->transactionId
            : null;

        return [
            'transactions' => $data,
            'next_cursor'  => $nextCursor,
        ];
    }

    public function addFunds(string $cardToken, float $amountMajorUnit): string
    {
        $response = $this->client()->post("/cards/{$cardToken}/funds", [
            'amount' => $amountMajorUnit,
        ])->throw();

        return (string) ($response->json('data.balance') ?? '0.00');
    }

    public function getBalance(string $cardToken): string
    {
        $response = $this->client()->get("/cards/{$cardToken}/balance")->throw();

        return (string) ($response->json('data.balance') ?? '0.00');
    }

    public function updateSpendingLimits(string $cardToken, array $limits): bool
    {
        return $this->client()->patch("/cards/{$cardToken}/limits", $limits)->successful();
    }

    public function updateSecuritySettings(string $cardToken, array $settings): bool
    {
        return $this->client()->patch("/cards/{$cardToken}/security", $settings)->successful();
    }

    /**
     * @param array<string, mixed> $card
     * @param array<string, mixed> $metadata
     */
    private function mapVirtualCard(array $card, ?string $label = null, array $metadata = []): VirtualCard
    {
        $expiry = isset($card['expiration_date'])
            ? new DateTimeImmutable((string) $card['expiration_date'])
            : new DateTimeImmutable(sprintf(
                '%s-%s-01',
                (string) ($card['exp_year'] ?? date('Y')),
                str_pad((string) ($card['exp_month'] ?? '12'), 2, '0', STR_PAD_LEFT),
            ));

        return new VirtualCard(
            cardToken: (string) ($card['id'] ?? ''),
            last4: (string) ($card['last4'] ?? '0000'),
            network: $this->mapNetwork((string) ($card['network'] ?? 'visa')),
            status: $this->mapStatus((string) ($card['status'] ?? 'pending')),
            cardholderName: (string) ($card['cardholder_name'] ?? $card['name'] ?? 'Unknown'),
            expiresAt: $expiry,
            metadata: array_merge($metadata, ['rain_card_id' => (string) ($card['id'] ?? '')]),
            label: $label,
        );
    }

    private function mapStatus(string $status): CardStatus
    {
        return match (strtolower($status)) {
            'active' => CardStatus::ACTIVE,
            'frozen', 'blocked' => CardStatus::FROZEN,
            'cancelled', 'canceled' => CardStatus::CANCELLED,
            'expired' => CardStatus::EXPIRED,
            default   => CardStatus::PENDING,
        };
    }

    private function mapNetwork(string $network): CardNetwork
    {
        return match (strtolower($network)) {
            'mastercard' => CardNetwork::MASTERCARD,
            default      => CardNetwork::VISA,
        };
    }

    private function mapTransactionStatus(string $status): string
    {
        return match (strtolower($status)) {
            'settled', 'completed' => 'settled',
            'declined', 'failed' => 'declined',
            default => 'pending',
        };
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ]);
    }
}
