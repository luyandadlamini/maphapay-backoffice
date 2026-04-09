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
use Illuminate\Support\Facades\DB;
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
            'id'                => (string) Str::uuid(),
            'requester_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'status'            => MoneyRequest::STATUS_FULFILLED,
            'trx'               => $trx,
        ]);

        AuthorizedTransaction::query()->create([
            'user_id' => $sender->id,
            'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'     => $trx,
            'payload' => [
                'money_request_id'     => $moneyRequest->id,
                '_verification_policy' => [
                    'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
                    'reason'            => 'user_preference',
                    'risk_reason'       => null,
                ],
            ],
            'result' => [
                'trx'        => $trx,
                'reference'  => $reference,
                'amount'     => '10.00',
                'asset_code' => 'SZL',
            ],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);

        AssetTransfer::query()->create([
            'uuid'              => $reference,
            'reference'         => $reference,
            'transfer_id'       => $reference,
            'from_account_uuid' => (string) Str::uuid(),
            'to_account_uuid'   => (string) Str::uuid(),
            'from_asset_code'   => 'SZL',
            'to_asset_code'     => 'SZL',
            'from_amount'       => 1000,
            'to_amount'         => 1000,
            'status'            => 'completed',
        ]);

        TransactionProjection::query()->create([
            'uuid'         => (string) Str::uuid(),
            'account_uuid' => (string) Str::uuid(),
            'asset_code'   => 'SZL',
            'amount'       => 1000,
            'type'         => 'transfer_out',
            'subtype'      => 'send_money',
            'description'  => 'Transfer out',
            'reference'    => $reference,
            'hash'         => hash('sha256', 'one'),
            'status'       => 'completed',
        ]);
        TransactionProjection::query()->create([
            'uuid'         => (string) Str::uuid(),
            'account_uuid' => (string) Str::uuid(),
            'asset_code'   => 'SZL',
            'amount'       => 1000,
            'type'         => 'transfer_in',
            'subtype'      => 'send_money',
            'description'  => 'Transfer in',
            'reference'    => $reference,
            'hash'         => hash('sha256', 'two'),
            'status'       => 'completed',
        ]);

        $result = app(MoneyMovementTransactionInspector::class)->inspect(reference: $reference);

        $this->assertSame($reference, $result['lookup']['reference']);
        $this->assertSame($trx, $result['authorized_transaction']['trx']);
        $this->assertSame([], $result['ledger_posting']);
        $this->assertSame('completed', $result['asset_transfer']['status']);
        $this->assertCount(2, $result['transaction_projections']);
        $this->assertSame($moneyRequest->id, $result['money_request']['id']);
        $this->assertSame('legacy_projection_only', $result['projection_state']['status']);
        $this->assertSame(2, $result['projection_state']['count']);
        $this->assertContains(
            'Transaction projections exist without a ledger posting. Treat this movement as legacy pre-cutover unless a posting backfill is explicitly documented.',
            $result['warnings'],
        );
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
            'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'     => $trx,
            'payload' => [
                '_verification_policy' => [
                    'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
                    'reason'            => 'risk_signal',
                    'risk_reason'       => 'recent_verification_failures',
                ],
            ],
            'status'                => AuthorizedTransaction::STATUS_FAILED,
            'verification_type'     => AuthorizedTransaction::VERIFICATION_OTP,
            'verification_failures' => 3,
            'failure_reason'        => 'Verification attempt limit exceeded.',
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
            'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'     => $trx,
            'payload' => [],
            'result'  => [
                'trx'        => $trx,
                'reference'  => $reference,
                'amount'     => '10.00',
                'asset_code' => 'SZL',
            ],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);

        AssetTransfer::query()->create([
            'uuid'              => $reference,
            'reference'         => $reference,
            'transfer_id'       => $reference,
            'from_account_uuid' => (string) Str::uuid(),
            'to_account_uuid'   => (string) Str::uuid(),
            'from_asset_code'   => 'SZL',
            'to_asset_code'     => 'SZL',
            'from_amount'       => 1000,
            'to_amount'         => 1000,
            'status'            => 'completed',
        ]);

        TransactionProjection::query()->create([
            'uuid'         => (string) Str::uuid(),
            'account_uuid' => (string) Str::uuid(),
            'asset_code'   => 'SZL',
            'amount'       => 1000,
            'type'         => 'transfer_out',
            'subtype'      => 'send_money',
            'description'  => 'Transfer out',
            'reference'    => $reference,
            'hash'         => hash('sha256', 'only-one'),
            'status'       => 'completed',
        ]);

        $result = app(MoneyMovementTransactionInspector::class)->inspect(reference: $reference);

        $this->assertCount(1, $result['transaction_projections']);
        $this->assertContains(
            'Transfer projection count mismatch: expected 2 account-facing transaction_projections rows for an internal P2P transfer.',
            $result['warnings'],
        );
    }

    #[Test]
    public function it_inspects_request_money_accept_lifecycle_by_trx(): void
    {
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $requester = User::factory()->create();
        $recipient = User::factory()->create();
        $reference = 'REF-' . Str::upper(Str::random(10));
        $trx = 'TRX-' . Str::upper(Str::random(10));

        $moneyRequest = MoneyRequest::query()->create([
            'id'                => (string) Str::uuid(),
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '25.00',
            'asset_code'        => 'SZL',
            'status'            => MoneyRequest::STATUS_FULFILLED,
            'trx'               => $trx,
        ]);

        AuthorizedTransaction::query()->create([
            'user_id' => $recipient->id,
            'remark'  => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'trx'     => $trx,
            'payload' => [
                'money_request_id'     => $moneyRequest->id,
                '_verification_policy' => [
                    'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
                    'reason'            => 'default_step_up',
                    'risk_reason'       => null,
                ],
            ],
            'result' => [
                'trx'        => $trx,
                'reference'  => $reference,
                'amount'     => '25.00',
                'asset_code' => 'SZL',
            ],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
        ]);

        AssetTransfer::query()->create([
            'uuid'              => $reference,
            'reference'         => $reference,
            'transfer_id'       => $reference,
            'from_account_uuid' => (string) Str::uuid(),
            'to_account_uuid'   => (string) Str::uuid(),
            'from_asset_code'   => 'SZL',
            'to_asset_code'     => 'SZL',
            'from_amount'       => 2500,
            'to_amount'         => 2500,
            'status'            => 'completed',
        ]);

        TransactionProjection::query()->create([
            'uuid'         => (string) Str::uuid(),
            'account_uuid' => (string) Str::uuid(),
            'asset_code'   => 'SZL',
            'amount'       => 2500,
            'type'         => 'transfer_out',
            'subtype'      => 'request_money_accept',
            'description'  => 'Money request accepted',
            'reference'    => $reference,
            'hash'         => hash('sha256', 'request-accept-one'),
            'status'       => 'completed',
        ]);
        TransactionProjection::query()->create([
            'uuid'         => (string) Str::uuid(),
            'account_uuid' => (string) Str::uuid(),
            'asset_code'   => 'SZL',
            'amount'       => 2500,
            'type'         => 'transfer_in',
            'subtype'      => 'request_money_accept',
            'description'  => 'Money request fulfilled',
            'reference'    => $reference,
            'hash'         => hash('sha256', 'request-accept-two'),
            'status'       => 'completed',
        ]);

        $result = app(MoneyMovementTransactionInspector::class)->inspect(trx: $trx);

        $this->assertSame($trx, $result['lookup']['trx']);
        $this->assertSame($reference, $result['lookup']['reference']);
        $this->assertSame(AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED, $result['authorized_transaction']['remark']);
        $this->assertSame([], $result['ledger_posting']);
        $this->assertSame($moneyRequest->id, $result['money_request']['id']);
        $this->assertSame('transfer_completed', $result['timeline'][3]['event']);
        $this->assertSame('money_request_state', $result['timeline'][4]['event']);
        $this->assertSame('legacy_projection_only', $result['projection_state']['status']);
        $this->assertSame(2, $result['projection_state']['count']);
        $this->assertContains(
            'Transaction projections exist without a ledger posting. Treat this movement as legacy pre-cutover unless a posting backfill is explicitly documented.',
            $result['warnings'],
        );
    }

    #[Test]
    public function it_returns_posting_data_separately_from_workflow_and_projection_state(): void
    {
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $requester = User::factory()->create();
        $recipient = User::factory()->create();
        $reference = 'REF-' . Str::upper(Str::random(10));
        $trx = 'TRX-' . Str::upper(Str::random(10));
        $postingId = (string) Str::uuid();

        AuthorizedTransaction::query()->create([
            'user_id' => $requester->id,
            'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'     => $trx,
            'payload' => [],
            'result'  => [
                'trx'        => $trx,
                'reference'  => $reference,
                'amount'     => '10.00',
                'asset_code' => 'SZL',
                'posting'    => [
                    'id'                 => $postingId,
                    'posting_type'       => 'send_money',
                    'status'             => 'posted',
                    'transfer_reference' => $reference,
                ],
            ],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);

        DB::table('ledger_postings')->insert([
            'id'                         => $postingId,
            'authorized_transaction_trx' => $trx,
            'posting_type'               => 'send_money',
            'status'                     => 'posted',
            'asset_code'                 => 'SZL',
            'transfer_reference'         => $reference,
            'posted_at'                  => now(),
            'entries_hash'               => hash('sha256', $trx),
            'metadata'                   => json_encode(['rule_version' => 1], JSON_THROW_ON_ERROR),
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        DB::table('ledger_entries')->insert([
            [
                'id'                => (string) Str::uuid(),
                'ledger_posting_id' => $postingId,
                'account_uuid'      => (string) Str::uuid(),
                'asset_code'        => 'SZL',
                'signed_amount'     => -1000,
                'entry_type'        => 'debit',
                'created_at'        => now(),
                'updated_at'        => now(),
            ],
            [
                'id'                => (string) Str::uuid(),
                'ledger_posting_id' => $postingId,
                'account_uuid'      => (string) Str::uuid(),
                'asset_code'        => 'SZL',
                'signed_amount'     => 1000,
                'entry_type'        => 'credit',
                'created_at'        => now(),
                'updated_at'        => now(),
            ],
        ]);

        $result = app(MoneyMovementTransactionInspector::class)->inspect(trx: $trx);

        $this->assertSame($postingId, $result['ledger_posting']['id']);
        $this->assertSame('posted', $result['ledger_posting']['status']);
        $this->assertSame('send_money', $result['ledger_posting']['posting_type']);
        $this->assertCount(2, $result['ledger_posting']['entries']);
        $this->assertSame('lagging', $result['projection_state']['status']);
        $this->assertSame(0, $result['projection_state']['count']);
        $this->assertSame($postingId, $result['projection_state']['ledger_posting_id']);
        $this->assertContains(
            'Ledger posting exists but no matching transaction_projections were found for this post-cutover movement.',
            $result['warnings'],
        );
    }

    #[Test]
    public function it_warns_when_transfer_exists_without_any_matching_transaction_projections(): void
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
            'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'     => $trx,
            'payload' => [],
            'result'  => [
                'trx'        => $trx,
                'reference'  => $reference,
                'amount'     => '10.00',
                'asset_code' => 'SZL',
            ],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);

        AssetTransfer::query()->create([
            'uuid'              => $reference,
            'reference'         => $reference,
            'transfer_id'       => $reference,
            'from_account_uuid' => (string) Str::uuid(),
            'to_account_uuid'   => (string) Str::uuid(),
            'from_asset_code'   => 'SZL',
            'to_asset_code'     => 'SZL',
            'from_amount'       => 1000,
            'to_amount'         => 1000,
            'status'            => 'completed',
        ]);

        $result = app(MoneyMovementTransactionInspector::class)->inspect(reference: $reference);

        $this->assertSame([], $result['transaction_projections']);
        $this->assertContains(
            'Transfer exists in asset_transfers but no matching transaction_projections were found.',
            $result['warnings'],
        );
    }
}
