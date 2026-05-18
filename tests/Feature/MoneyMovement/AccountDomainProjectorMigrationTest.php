<?php

declare(strict_types=1);

namespace Tests\Feature\MoneyMovement;

use App\Domain\Account\DataObjects\Account as AccountDataObject;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Events\AccountCreated;
use App\Domain\Account\Events\AccountDeleted;
use App\Domain\Account\Events\AccountFrozen;
use App\Domain\Account\Events\AccountUnfrozen;
use App\Domain\Account\Events\MoneyAdded;
use App\Domain\Account\Events\MoneySubtracted;
use App\Domain\Account\Events\PointsAwarded;
use App\Domain\Account\Events\PointsDeducted;
use App\Domain\Account\Events\RedemptionApproved;
use App\Domain\Account\Events\RedemptionDeclined;
use App\Domain\Account\Projectors\AccountProjector;
use App\Domain\Account\Projectors\MinorPointsProjector;
use App\Domain\Account\Projectors\MinorRedemptionProjector;
use App\Domain\Account\Projectors\TransactionProjector;
use App\Domain\Account\Projectors\TurnoverProjector;
use App\Domain\Shared\Contracts\CarriesTenantContext;
use App\Domain\Shared\EventSourcing\TenantAwareProjector;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

/**
 * Migration contract test: every Account-domain projector handles events
 * tenant-aware. Mirrors AssetBalanceProjectionTest.
 *
 * The point of this test is not "does projection X produce row Y" — the
 * individual projectors have their own behavioural tests for that. Here we
 * lock in the *invariant* that protects against the AssetBalanceProjector
 * class of bug: every concrete projector that touches tenant-scoped state
 * must auto-initialize tenancy from the event before invoking handlers.
 */
class AccountDomainProjectorMigrationTest extends DomainTestCase
{
    /** @return iterable<string, array{0:class-string}> */
    public static function migratedProjectorsProvider(): iterable
    {
        yield 'AccountProjector' => [AccountProjector::class];
        yield 'TransactionProjector' => [TransactionProjector::class];
        yield 'TurnoverProjector' => [TurnoverProjector::class];
        yield 'MinorPointsProjector' => [MinorPointsProjector::class];
        yield 'MinorRedemptionProjector' => [MinorRedemptionProjector::class];
    }

    #[Test]
    #[DataProvider('migratedProjectorsProvider')]
    public function projector_extends_tenant_aware_projector(string $projectorClass): void
    {
        $this->assertInstanceOf(
            TenantAwareProjector::class,
            app($projectorClass),
            $projectorClass . ' must extend TenantAwareProjector so it auto-initializes '
            . 'tenancy from CarriesTenantContext events before invoking handlers.',
        );
    }

    #[Test]
    public function account_created_carries_tenant_context_via_aggregate_root_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new AccountCreated(
            account: new AccountDataObject(
                name:     'Test Account',
                userUuid: (string) Str::uuid(),
                uuid:     $accountUuid,
            ),
        );
        $event->setAggregateRootUuid($accountUuid);

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function account_deleted_carries_tenant_context_via_aggregate_root_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new AccountDeleted();
        $event->setAggregateRootUuid($accountUuid);

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function account_frozen_carries_tenant_context_via_aggregate_root_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new AccountFrozen(reason: 'compliance hold');
        $event->setAggregateRootUuid($accountUuid);

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function account_unfrozen_carries_tenant_context_via_aggregate_root_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new AccountUnfrozen(reason: 'compliance cleared');
        $event->setAggregateRootUuid($accountUuid);

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function money_added_carries_tenant_context_via_aggregate_root_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new MoneyAdded(
            money: new Money(1_000),
            hash:  Hash::fromData('money-added-tenant-test'),
        );
        $event->setAggregateRootUuid($accountUuid);

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function money_subtracted_carries_tenant_context_via_aggregate_root_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new MoneySubtracted(
            money: new Money(1_000),
            hash:  Hash::fromData('money-subtracted-tenant-test'),
        );
        $event->setAggregateRootUuid($accountUuid);

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function points_awarded_carries_tenant_context_via_minor_account_uuid(): void
    {
        $minorAccountUuid = (string) Str::uuid();

        $event = new PointsAwarded(
            minorAccountUuid: $minorAccountUuid,
            points:           50,
            source:           'chore',
            description:      'Tidied room',
            referenceId:      null,
        );

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($minorAccountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function points_deducted_carries_tenant_context_via_minor_account_uuid(): void
    {
        $minorAccountUuid = (string) Str::uuid();

        $event = new PointsDeducted(
            minorAccountUuid: $minorAccountUuid,
            points:           25,
            source:           'redemption',
            description:      'Reward redeemed',
            referenceId:      null,
        );

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($minorAccountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function redemption_approved_carries_tenant_context_via_minor_account_uuid(): void
    {
        $minorAccountUuid = (string) Str::uuid();

        $event = new RedemptionApproved(
            redemptionId:        (string) Str::uuid(),
            minorAccountUuid:    $minorAccountUuid,
            guardianAccountUuid: (string) Str::uuid(),
            pointsCost:          100,
        );

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($minorAccountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function redemption_declined_carries_tenant_context_via_minor_account_uuid(): void
    {
        $minorAccountUuid = (string) Str::uuid();

        $event = new RedemptionDeclined(
            redemptionId:        (string) Str::uuid(),
            minorAccountUuid:    $minorAccountUuid,
            guardianAccountUuid: (string) Str::uuid(),
        );

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($minorAccountUuid, $event->tenantAccountUuid());
    }

    #[Test]
    public function asset_transaction_created_carries_tenant_context_via_account_uuid(): void
    {
        $accountUuid = (string) Str::uuid();

        $event = new \App\Domain\Asset\Events\AssetTransactionCreated(
            accountUuid: \App\Domain\Account\DataObjects\AccountUuid::fromString($accountUuid),
            assetCode:   'SZL',
            money:       new Money(1_000),
            type:        'credit',
            hash:        Hash::fromData('asset-tx-created-tenant-test'),
        );

        $this->assertInstanceOf(CarriesTenantContext::class, $event);
        $this->assertSame($accountUuid, $event->tenantAccountUuid());
    }
}
