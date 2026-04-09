<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Projectors;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\Models\Account;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Projectors\AssetBalanceProjector;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Events\AssetTransferCompleted;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class AssetBalanceProjectorTest extends DomainTestCase
{
    #[Test]
    public function it_skips_event_driven_balance_updates_for_posting_anchored_internal_transfers(): void
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
            'asset_code' => 'SZL',
            'balance' => 500_000,
        ]);

        AccountBalance::query()->create([
            'account_uuid' => $toAccountUuid,
            'asset_code' => 'SZL',
            'balance' => 0,
        ]);

        $event = new AssetTransferCompleted(
            fromAccountUuid: AccountUuid::fromString($fromAccountUuid),
            toAccountUuid: AccountUuid::fromString($toAccountUuid),
            fromAssetCode: 'SZL',
            toAssetCode: 'SZL',
            fromAmount: new Money(1_000),
            toAmount: new Money(1_000),
            hash: Hash::fromData('posting-anchored-balance-skip'),
            description: 'Posting-anchored transfer',
            transferId: 'REF-' . Str::upper(Str::random(10)),
            metadata: [
                'source' => 'p2p',
                'operation_type' => 'send_money',
                'money_state_anchor' => 'ledger_posting',
            ],
        );

        app(AssetBalanceProjector::class)->onAssetTransferCompleted($event);

        $this->assertSame(500_000, AccountBalance::query()->where('account_uuid', $fromAccountUuid)->where('asset_code', 'SZL')->value('balance'));
        $this->assertSame(0, AccountBalance::query()->where('account_uuid', $toAccountUuid)->where('asset_code', 'SZL')->value('balance'));
    }
}
