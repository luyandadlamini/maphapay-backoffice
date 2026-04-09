<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ledger\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Ledger\Services\LedgerPostingService;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class LedgerPostingServiceTest extends DomainTestCase
{
    #[Test]
    public function it_applies_account_balance_read_models_from_posted_entries_for_send_money(): void
    {
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $fromAccount = Account::factory()->forUser($sender)->create([
            'uuid' => (string) Str::uuid(),
        ]);
        $toAccount = Account::factory()->forUser($recipient)->create([
            'uuid' => (string) Str::uuid(),
        ]);

        AccountBalance::query()->updateOrCreate(
            ['account_uuid' => $fromAccount->uuid, 'asset_code' => 'SZL'],
            ['balance' => 500_000],
        );
        AccountBalance::query()->updateOrCreate(
            ['account_uuid' => $toAccount->uuid, 'asset_code' => 'SZL'],
            ['balance' => 0],
        );

        $transaction = AuthorizedTransaction::query()->create([
            'user_id' => $sender->id,
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx' => 'TRX-' . Str::upper(Str::random(10)),
            'payload' => [
                'from_account_uuid' => $fromAccount->uuid,
                'to_account_uuid' => $toAccount->uuid,
                'amount' => '10.00',
                'asset_code' => 'SZL',
                'reference' => 'REF-' . Str::upper(Str::random(10)),
            ],
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);

        $result = app(LedgerPostingService::class)->createForAuthorizedTransaction($transaction, [
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'reference' => $transaction->payload['reference'],
        ]);

        $this->assertNotNull($result);
        $this->assertSame(499_000, AccountBalance::query()->where('account_uuid', $fromAccount->uuid)->where('asset_code', 'SZL')->value('balance'));
        $this->assertSame(1_000, AccountBalance::query()->where('account_uuid', $toAccount->uuid)->where('asset_code', 'SZL')->value('balance'));
    }
}
