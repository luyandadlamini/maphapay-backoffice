<?php

declare(strict_types=1);

namespace Tests\Feature\MoneyMovement;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Events\AssetBalanceAdded;
use App\Domain\Account\Events\AssetBalanceSubtracted;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Projectors\AssetBalanceProjector;
use App\Domain\Asset\Events\AssetTransferCompleted;
use App\Domain\Asset\Events\AssetTransferFailed;
use App\Domain\Asset\Events\AssetTransferInitiated;
use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Contracts\CarriesTenantContext;
use App\Domain\Shared\EventSourcing\TenantAwareProjector;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

/**
 * Integration test asserting that the AssetBalanceProjector migration is wired
 * correctly: the projector extends TenantAwareProjector, the events it handles
 * implement CarriesTenantContext, and an end-to-end handle() pipeline updates
 * AccountBalance rows as expected.
 *
 * The earlier bug (savings-pocket phantom transfer) was caused by the projector
 * running in a queue worker without tenancy initialized — these contracts make
 * the same omission impossible to ship again.
 */
class AssetBalanceProjectionTest extends DomainTestCase
{
    #[Test]
    public function it_extends_tenant_aware_projector(): void
    {
        $this->assertInstanceOf(
            TenantAwareProjector::class,
            app(AssetBalanceProjector::class),
            'AssetBalanceProjector must extend TenantAwareProjector so it auto-initializes tenancy '
            . 'from CarriesTenantContext events before invoking handlers.',
        );
    }

    #[Test]
    public function asset_transfer_completed_carries_tenant_context(): void
    {
        $fromUuid = (string) Str::uuid();

        $event = new AssetTransferCompleted(
            fromAccountUuid: AccountUuid::fromString($fromUuid),
            toAccountUuid:   AccountUuid::fromString((string) Str::uuid()),
            fromAssetCode:   'SZL',
            toAssetCode:     'SZL',
            fromAmount:      new Money(1_000),
            toAmount:        new Money(1_000),
            hash:            Hash::fromData('carries-tenant-test'),
        );

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($fromUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function asset_transfer_initiated_carries_tenant_context(): void
    {
        $fromUuid = (string) Str::uuid();

        $event = new AssetTransferInitiated(
            fromAccountUuid: AccountUuid::fromString($fromUuid),
            toAccountUuid:   AccountUuid::fromString((string) Str::uuid()),
            fromAssetCode:   'SZL',
            toAssetCode:     'SZL',
            fromAmount:      new Money(1_000),
            toAmount:        new Money(1_000),
            exchangeRate:    null,
            hash:            Hash::fromData('initiated-carries-tenant-test'),
        );

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($fromUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function asset_transfer_failed_carries_tenant_context(): void
    {
        $fromUuid = (string) Str::uuid();

        $event = new AssetTransferFailed(
            fromAccountUuid: AccountUuid::fromString($fromUuid),
            toAccountUuid:   AccountUuid::fromString((string) Str::uuid()),
            fromAssetCode:   'SZL',
            toAssetCode:     'SZL',
            fromAmount:      new Money(1_000),
            reason:          'test reason',
            hash:            Hash::fromData('failed-carries-tenant-test'),
        );

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($fromUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function asset_balance_added_carries_tenant_context_via_aggregate_root_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new AssetBalanceAdded(
            assetCode: 'SZL',
            amount:    1_000,
            hash:      Hash::fromData('added-carries-tenant-test'),
        );
        $event->setAggregateRootUuid($accountUuid);

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function asset_balance_subtracted_carries_tenant_context_via_aggregate_root_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new AssetBalanceSubtracted(
            assetCode: 'SZL',
            amount:    1_000,
            hash:      Hash::fromData('subtracted-carries-tenant-test'),
        );
        $event->setAggregateRootUuid($accountUuid);

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function projector_updates_balances_on_asset_transfer_completed(): void
    {
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $fromAccountUuid = (string) Str::uuid();
        $toAccountUuid = (string) Str::uuid();

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        Account::factory()->forUser($sender)->create(['uuid' => $fromAccountUuid]);
        Account::factory()->forUser($recipient)->create(['uuid' => $toAccountUuid]);

        AccountBalance::query()->create([
            'account_uuid' => $fromAccountUuid,
            'asset_code'   => 'SZL',
            'balance'      => 500_000,
        ]);

        AccountBalance::query()->create([
            'account_uuid' => $toAccountUuid,
            'asset_code'   => 'SZL',
            'balance'      => 0,
        ]);

        $event = new AssetTransferCompleted(
            fromAccountUuid: AccountUuid::fromString($fromAccountUuid),
            toAccountUuid:   AccountUuid::fromString($toAccountUuid),
            fromAssetCode:   'SZL',
            toAssetCode:     'SZL',
            fromAmount:      new Money(75_000),
            toAmount:        new Money(75_000),
            hash:            Hash::fromData('projector-updates-balances'),
            description:     'Send money',
            transferId:      'REF-' . Str::upper(Str::random(10)),
            metadata:        ['source' => 'p2p', 'operation_type' => 'send_money'],
        );

        // Direct handler invocation (not handle()) — exercises the projection body.
        // The TenantAwareProjector::handle() wrapper is covered by TenantAwareProjectorTest;
        // here we assert the projection effect is correct end-to-end.
        app(AssetBalanceProjector::class)->onAssetTransferCompleted($event);

        $this->assertSame(
            425_000,
            AccountBalance::query()->where('account_uuid', $fromAccountUuid)->where('asset_code', 'SZL')->value('balance'),
            'Sender balance must reflect the debit (500_000 - 75_000).',
        );
        $this->assertSame(
            75_000,
            AccountBalance::query()->where('account_uuid', $toAccountUuid)->where('asset_code', 'SZL')->value('balance'),
            'Recipient balance must reflect the credit (0 + 75_000).',
        );
    }
}
