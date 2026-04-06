<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Services;

use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account;
use App\Domain\Asset\Aggregates\AssetTransferAggregate;
use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Money\MoneyConverter;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Canonical internal P2P transfer executor for compat money movement.
 *
 * Send-money and request-money acceptance both use this service so funds move
 * through the same aggregate-backed execution path and produce the same event trail.
 */
class InternalP2pTransferService
{
    /**
     * @return array{amount: string, asset_code: string, reference: string}
     */
    public function execute(
        string $fromAccountUuid,
        string $toAccountUuid,
        string $amount,
        string $assetCode,
        string $reference,
        string $operationType = 'send_money',
        ?string $note = null,
    ): array {
        $asset = Asset::query()->where('code', $assetCode)->firstOrFail();
        $amountMinor = MoneyConverter::forAsset($amount, $asset);
        $sender = $this->resolveAccountOwner($fromAccountUuid);
        $recipient = $this->resolveAccountOwner($toAccountUuid);
        $metadata = array_filter([
            'source'         => 'p2p',
            'operation_type' => $operationType,
            'note'           => $note,
            'p2p_display'    => [
                'sender_label'    => $this->userLabel($sender),
                'recipient_label' => $this->userLabel($recipient),
                'note_preview'    => $note,
            ],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $aggregate = AssetTransferAggregate::retrieve($reference);
            $money = new Money((int) $amountMinor);

            if ($aggregate->getStatus() === null) {
                $aggregate->initiate(
                    fromAccountUuid: __account_uuid($fromAccountUuid),
                    toAccountUuid: __account_uuid($toAccountUuid),
                    fromAssetCode: $assetCode,
                    toAssetCode: $assetCode,
                    fromAmount: $money,
                    toAmount: $money,
                    description: "Transfer: {$reference}",
                    metadata: $metadata,
                );
            }

            $aggregate
                ->complete($reference, $metadata)
                ->persist();
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Failed to persist internal P2P transfer [%s]: %s', $reference, $e->getMessage()),
                previous: $e,
            );
        }

        return [
            'amount'     => MoneyConverter::toMajorUnitString($amountMinor, $asset->precision),
            'asset_code' => $assetCode,
            'reference'  => $reference,
        ];
    }

    private function resolveAccountOwner(string $accountUuid): ?User
    {
        $account = Account::query()
            ->with('user')
            ->where('uuid', $accountUuid)
            ->first();

        /** @var User|null $owner */
        $owner = $account?->user;

        return $owner;
    }

    private function userLabel(?User $user): string
    {
        if ($user === null) {
            return 'contact';
        }

        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $username = trim((string) ($user->username ?? ''));
        if ($username !== '') {
            return $username;
        }

        $mobile = trim((string) ($user->mobile ?? ''));
        if ($mobile !== '') {
            return $mobile;
        }

        return 'contact';
    }
}
