<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Monitoring\Services;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\AssetTransfer;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Monitoring\Services\MoneyMovementTransactionInspector;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class MoneyMovementTransactionInspectorTest extends DomainTestCase
{
    #[Test]
    public function it_joins_the_money_movement_lifecycle_by_transaction_reference(): void
    {
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $reference = 'REF-' . Str::upper(Str::random(10));
        $trx = 'TRX-' . Str::upper(Str::random(10));

        $moneyRequest = MoneyRequest::query()->create([
            'id' => (string) Str::uuid(),
            'requester_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'status' => MoneyRequest::STATUS_FULFILLED,
            'trx' => $trx,
        ]);

        AuthorizedTransaction::query()->create([
            'user_id' => $sender->id,
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx' => $trx,
            'payload' => [
                'money_request_id' => $moneyRequest->id,
                '_verification_policy' => [
                    'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
                    'reason' => 'user_preference',
                    'risk_reason' => null,
                ],
            ],
            'result' => [
                'trx' => $trx,
                'reference' => $reference,
                'amount' => '10.00',
                'asset_code' => 'SZL',
            ],
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);

        AssetTransfer::query()->create([
            'uuid' => $reference,
            'reference' => $reference,
            'transfer_id' => $reference,
            'from_account_uuid' => (string) Str::uuid(),
            'to_account_uuid' => (string) Str::uuid(),
            'from_asset_code' => 'SZL',
            'to_asset_code' => 'SZL',
            'from_amount' => 1000,
            'to_amount' => 1000,
            'status' => 'completed',
        ]);

        TransactionProjection::query()->create([
            'uuid' => (string) Str::uuid(),
            'account_uuid' => (string) Str::uuid(),
            'asset_code' => 'SZL',
            'amount' => 1000,
            'type' => 'transfer_out',
            'subtype' => 'send_money',
            'description' => 'Transfer out',
            'reference' => $reference,
            'hash' => hash('sha256', 'one'),
            'status' => 'completed',
        ]);
        TransactionProjection::query()->create([
            'uuid' => (string) Str::uuid(),
            'account_uuid' => (string) Str::uuid(),
            'asset_code' => 'SZL',
            'amount' => 1000,
            'type' => 'transfer_in',
            'subtype' => 'send_money',
            'description' => 'Transfer in',
            'reference' => $reference,
            'hash' => hash('sha256', 'two'),
            'status' => 'completed',
        ]);

        $result = app(MoneyMovementTransactionInspector::class)->inspect(reference: $reference);

        $this->assertSame($reference, $result['lookup']['reference']);
        $this->assertSame($trx, $result['authorized_transaction']['trx']);
        $this->assertSame('completed', $result['asset_transfer']['status']);
        $this->assertCount(2, $result['transaction_projections']);
        $this->assertSame($moneyRequest->id, $result['money_request']['id']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame('challenge_decision', $result['timeline'][1]['event']);
    }
}
