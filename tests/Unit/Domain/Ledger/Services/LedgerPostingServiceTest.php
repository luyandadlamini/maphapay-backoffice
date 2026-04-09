<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ledger\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\TransactionProjection;
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

    #[Test]
    public function it_creates_transaction_projections_from_posted_entries_for_new_cutover_internal_movements(): void
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
        $reference = 'REF-' . Str::upper(Str::random(10));

        $transaction = AuthorizedTransaction::query()->create([
            'user_id' => $sender->id,
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx' => 'TRX-' . Str::upper(Str::random(10)),
            'payload' => [
                'from_account_uuid' => $fromAccount->uuid,
                'to_account_uuid' => $toAccount->uuid,
                'amount' => '10.00',
                'asset_code' => 'SZL',
                'reference' => $reference,
                'note' => 'Lunch',
            ],
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);

        app(LedgerPostingService::class)->createForAuthorizedTransaction($transaction, [
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'reference' => $reference,
        ]);

        $projections = TransactionProjection::query()
            ->where('reference', $reference)
            ->orderBy('type')
            ->get();

        $this->assertCount(2, $projections);
        $this->assertSame('ledger_posting', $projections[0]->metadata['projection_anchor'] ?? null);
        $this->assertSame('posted', $projections[0]->metadata['ledger_posting_status'] ?? null);
        $this->assertSame($reference, $projections[0]->metadata['ledger_transfer_reference'] ?? null);
        $this->assertSame('send_money', $projections[0]->metadata['operation_type'] ?? null);
        $this->assertSame('Lunch', $projections[0]->metadata['display']['note_preview'] ?? null);
        $this->assertSame('ledger_posting', $projections[1]->metadata['projection_anchor'] ?? null);
    }
}
