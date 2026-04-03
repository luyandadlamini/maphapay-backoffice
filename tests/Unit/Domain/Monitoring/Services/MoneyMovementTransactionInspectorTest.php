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

    #[Test]
    public function it_surfaces_failed_verification_attempts_in_the_timeline(): void
    {
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $user = User::factory()->create();
        $trx = 'TRX-' . Str::upper(Str::random(10));

        AuthorizedTransaction::query()->create([
            'user_id' => $user->id,
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx' => $trx,
            'payload' => [
                '_verification_policy' => [
                    'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
                    'reason' => 'risk_signal',
                    'risk_reason' => 'recent_verification_failures',
                ],
            ],
            'status' => AuthorizedTransaction::STATUS_FAILED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
            'verification_failures' => 3,
            'failure_reason' => 'Verification attempt limit exceeded.',
        ]);

        $result = app(MoneyMovementTransactionInspector::class)->inspect(trx: $trx);

        $this->assertSame($trx, $result['authorized_transaction']['trx']);
        $this->assertSame('verification_failed', $result['timeline'][2]['event']);
        $this->assertSame('Verification attempt limit exceeded.', $result['timeline'][2]['failure_reason']);
        $this->assertSame(3, $result['timeline'][2]['verification_failures']);
    }

    #[Test]
    public function it_warns_when_projection_count_does_not_match_the_expected_internal_transfer_shape(): void
    {
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $user = User::factory()->create();
        $reference = 'REF-' . Str::upper(Str::random(10));
        $trx = 'TRX-' . Str::upper(Str::random(10));

        AuthorizedTransaction::query()->create([
            'user_id' => $user->id,
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx' => $trx,
            'payload' => [],
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
            'hash' => hash('sha256', 'only-one'),
            'status' => 'completed',
        ]);

        $result = app(MoneyMovementTransactionInspector::class)->inspect(reference: $reference);

        $this->assertCount(1, $result['transaction_projections']);
        $this->assertContains(
            'Transfer projection count mismatch: expected 2 account-facing transaction_projections rows for an internal P2P transfer.',
            $result['warnings'],
        );
    }
}
