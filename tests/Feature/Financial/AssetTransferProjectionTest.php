<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Aggregates\AssetTransferAggregate;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\AssetTransfer;
use App\Domain\AuthorizedTransaction\Services\InternalP2pTransferService;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class AssetTransferProjectionTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    private Account $senderAccount;

    private Account $recipientAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        $this->sender = User::factory()->create();
        $this->recipient = User::factory()->create();
        $this->senderAccount = $this->createAccount($this->sender);
        $this->recipientAccount = $this->createAccount($this->recipient);

        AccountBalance::query()->updateOrCreate(
            [
                'account_uuid' => $this->senderAccount->uuid,
                'asset_code'   => 'SZL',
            ],
            ['balance' => 50_000],
        );

        AccountBalance::query()->updateOrCreate(
            [
                'account_uuid' => $this->recipientAccount->uuid,
                'asset_code'   => 'SZL',
            ],
            ['balance' => 0],
        );
    }

    #[Test]
    public function test_internal_p2p_transfer_persists_completed_asset_transfer_projection(): void
    {
        $reference = (string) Str::uuid();

        $result = app(InternalP2pTransferService::class)->execute(
            $this->senderAccount->uuid,
            $this->recipientAccount->uuid,
            '10.00',
            'SZL',
            $reference,
        );

        $this->assertSame('10.00', $result['amount']);
        $this->assertSame('SZL', $result['asset_code']);
        $this->assertSame($reference, $result['reference']);

        $this->assertDatabaseHas('asset_transfers', [
            'uuid'              => $reference,
            'reference'         => $reference,
            'transfer_id'       => $reference,
            'from_account_uuid' => $this->senderAccount->uuid,
            'to_account_uuid'   => $this->recipientAccount->uuid,
            'from_asset_code'   => 'SZL',
            'to_asset_code'     => 'SZL',
            'from_amount'       => 1000,
            'to_amount'         => 1000,
            'status'            => 'completed',
        ]);

        $transfer = AssetTransfer::query()->where('uuid', $reference)->first();
        $this->assertNotNull($transfer);
        $this->assertSame($reference, $transfer->transfer_id);
        $this->assertSame('Transfer: ' . $reference, $transfer->description);
        $this->assertIsArray($transfer->metadata);
        $this->assertSame(false, $transfer->metadata['is_cross_asset'] ?? null);
        $this->assertArrayHasKey('transfer_id', $transfer->metadata);
    }

    #[Test]
    public function test_asset_transfer_projection_records_failed_transfer_state(): void
    {
        $reference = (string) Str::uuid();

        AssetTransferAggregate::retrieve($reference)
            ->initiate(
                fromAccountUuid: __account_uuid($this->senderAccount->uuid),
                toAccountUuid: __account_uuid($this->recipientAccount->uuid),
                fromAssetCode: 'SZL',
                toAssetCode: 'SZL',
                fromAmount: new Money(250),
                toAmount: new Money(250),
                exchangeRate: 1.0,
                description: 'Projection failure path',
                metadata: ['origin' => 'asset-transfer-projection-test'],
            )
            ->fail(
                reason: 'Insufficient balance.',
                transferId: $reference,
                metadata: ['reason_code' => 'insufficient_balance'],
            )
            ->persist();

        $this->assertDatabaseHas('asset_transfers', [
            'uuid'           => $reference,
            'transfer_id'    => $reference,
            'status'         => 'failed',
            'failure_reason' => 'Insufficient balance.',
        ]);

        $transfer = AssetTransfer::query()->where('uuid', $reference)->first();
        $this->assertNotNull($transfer);
        $this->assertSame(250, $transfer->from_amount);
        $this->assertSame(250, $transfer->to_amount);
        $this->assertSame('asset-transfer-projection-test', $transfer->metadata['origin'] ?? null);
        $this->assertSame('insufficient_balance', $transfer->metadata['reason_code'] ?? null);
    }

    #[Test]
    public function test_internal_p2p_transfer_is_idempotent_for_same_reference(): void
    {
        $reference = (string) Str::uuid();
        $service = app(InternalP2pTransferService::class);

        $service->execute(
            $this->senderAccount->uuid,
            $this->recipientAccount->uuid,
            '3.00',
            'SZL',
            $reference,
        );

        $service->execute(
            $this->senderAccount->uuid,
            $this->recipientAccount->uuid,
            '3.00',
            'SZL',
            $reference,
        );

        $this->assertSame(
            1,
            AssetTransfer::query()->where('uuid', $reference)->count(),
        );

        $transfer = AssetTransfer::query()->where('uuid', $reference)->first();
        $this->assertNotNull($transfer);
        $this->assertSame('completed', $transfer->status);
        $this->assertSame(300, $transfer->from_amount);
        $this->assertSame(300, $transfer->to_amount);
    }
}
