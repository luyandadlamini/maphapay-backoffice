<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Projectors;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Account\Projectors\TransactionProjector;
use App\Domain\Asset\Events\AssetTransferCompleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class TransactionProjectorTest extends DomainTestCase
{
    #[Test]
    public function it_stamps_new_internal_transfer_projections_with_resolved_ledger_posting_linkage(): void
    {
        $reference = 'REF-' . Str::upper(Str::random(10));
        $postingId = (string) Str::uuid();

        DB::table('ledger_postings')->insert([
            'id'                         => $postingId,
            'authorized_transaction_trx' => 'TRX-' . Str::upper(Str::random(10)),
            'posting_type'               => 'send_money',
            'status'                     => 'posted',
            'asset_code'                 => 'SZL',
            'transfer_reference'         => $reference,
            'posted_at'                  => now(),
            'entries_hash'               => hash('sha256', $reference),
            'metadata'                   => json_encode(['rule_version' => 1], JSON_THROW_ON_ERROR),
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        $event = new AssetTransferCompleted(
            fromAccountUuid: AccountUuid::fromString((string) Str::uuid()),
            toAccountUuid: AccountUuid::fromString((string) Str::uuid()),
            fromAssetCode: 'SZL',
            toAssetCode: 'SZL',
            fromAmount: new Money(1000),
            toAmount: new Money(1000),
            hash: Hash::fromData('ledger-linked-transfer'),
            description: 'Send money',
            transferId: $reference,
            metadata: ['operation_type' => 'send_money'],
        );

        app(TransactionProjector::class)->onAssetTransferCompleted($event);

        $projections = TransactionProjection::query()
            ->where('reference', $reference)
            ->orderBy('type')
            ->get();

        $this->assertCount(2, $projections);
        $this->assertSame($postingId, $projections[0]->metadata['ledger_posting_id'] ?? null);
        $this->assertSame('posted', $projections[0]->metadata['ledger_posting_status'] ?? null);
        $this->assertSame($reference, $projections[0]->metadata['ledger_transfer_reference'] ?? null);
        $this->assertSame('ledger_posting', $projections[0]->metadata['projection_anchor'] ?? null);
        $this->assertSame($postingId, $projections[1]->metadata['ledger_posting_id'] ?? null);
        $this->assertSame('ledger_posting', $projections[1]->metadata['projection_anchor'] ?? null);
    }
}
