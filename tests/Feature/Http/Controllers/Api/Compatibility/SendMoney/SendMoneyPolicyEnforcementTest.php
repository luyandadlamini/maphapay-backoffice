<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\SendMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\AssetTransfer;
use App\Domain\AuthorizedTransaction\Contracts\MoneyMovementRiskSignalProviderInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Ledger\Services\LedgerPostingService;
use App\Domain\Wallet\Events\Broadcast\WalletBalanceUpdated;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\ControllerTestCase;

class SendMoneyPolicyEnforcementTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    private Account $senderAccount;

    private Account $recipientAccount;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maphapay_migration.enable_send_money'                           => true,
            'maphapay_migration.enable_dashboard'                            => true,
            'maphapay_migration.enable_transaction_history'                  => true,
            'maphapay_migration.money_movement.send_money.step_up_threshold' => '100.00',
        ]);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        if (! Schema::hasColumn('transaction_projections', 'user_category_slug')) {
            Schema::table('transaction_projections', function (Blueprint $table): void {
                $table->string('analytics_bucket')->nullable()->after('status');
                $table->boolean('budget_eligible')->nullable()->after('analytics_bucket');
                $table->string('source_domain')->nullable()->after('budget_eligible');
                $table->string('system_category_slug')->nullable()->after('source_domain');
                $table->string('user_category_slug')->nullable()->after('system_category_slug');
                $table->string('effective_category_slug')->nullable()->after('user_category_slug');
                $table->string('categorization_source')->nullable()->after('effective_category_slug');
            });
        }

        $this->sender = User::factory()->create(['kyc_status' => 'approved', 'transaction_pin' => null]);
        $this->recipient = User::factory()->create(['kyc_status' => 'approved']);
        $this->senderAccount = Account::factory()->create([
            'user_uuid' => $this->sender->uuid,
            'frozen'    => false,
        ]);
        $this->recipientAccount = Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'frozen'    => false,
        ]);

        AccountBalance::query()->updateOrCreate(
            ['account_uuid' => $this->senderAccount->uuid, 'asset_code' => 'SZL'],
            ['balance' => 500_000],
        );
        AccountBalance::query()->updateOrCreate(
            ['account_uuid' => $this->recipientAccount->uuid, 'asset_code' => 'SZL'],
            ['balance' => 0],
        );
    }

    #[Test]
    public function it_finalizes_low_risk_send_money_synchronously_when_policy_allows_none(): void
    {
        Event::fake([WalletBalanceUpdated::class]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.balance', '5000.00');

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.balance', '0.00');

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000009901',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '10.00',
            'verification_type' => 'sms',
            'note'              => 'Lunch split',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.next_step', 'none');

        $trx = $response->json('data.trx');
        $reference = $response->json('data.reference');

        $this->assertIsString($trx);
        $this->assertIsString($reference);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'               => $trx,
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);
        $this->assertDatabaseHas('asset_transfers', [
            'reference' => $reference,
            'status'    => 'completed',
        ]);
        $this->assertDatabaseHas('account_balances', [
            'account_uuid' => $this->senderAccount->uuid,
            'asset_code'   => 'SZL',
            'balance'      => 499_000,
        ]);
        $this->assertDatabaseHas('account_balances', [
            'account_uuid' => $this->recipientAccount->uuid,
            'asset_code'   => 'SZL',
            'balance'      => 1_000,
        ]);
        $this->assertDatabaseHas('ledger_postings', [
            'authorized_transaction_trx' => $trx,
            'posting_type'               => 'send_money',
            'status'                     => 'posted',
            'asset_code'                 => 'SZL',
            'transfer_reference'         => $reference,
        ]);

        $postingId = DB::table('ledger_postings')
            ->where('authorized_transaction_trx', $trx)
            ->value('id');

        $this->assertNotNull($postingId);
        $this->assertSame(2, DB::table('ledger_entries')->where('ledger_posting_id', $postingId)->count());
        $this->assertSame(
            0,
            (int) DB::table('ledger_entries')
                ->where('ledger_posting_id', $postingId)
                ->sum('signed_amount'),
        );

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.balance', '4990.00');

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.balance', '10.00');

        $recipientTransactions = $this->getJson('/api/transactions')
            ->assertOk()
            ->json('data.transactions.data');

        $this->assertCount(1, $recipientTransactions);
        $this->assertSame('transfer_in', $recipientTransactions[0]['type']);
        $this->assertSame('send_money', $recipientTransactions[0]['subtype']);
        $this->assertSame('income', $recipientTransactions[0]['analytics_bucket']);
        $this->assertSame('peer_transfer', $recipientTransactions[0]['category_slug']);
        $this->assertFalse($recipientTransactions[0]['editable_category']);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);
        $senderTransactions = $this->getJson('/api/transactions')
            ->assertOk()
            ->json('data.transactions.data');

        $this->assertCount(1, $senderTransactions);
        $this->assertSame('transfer_out', $senderTransactions[0]['type']);
        $this->assertSame('send_money', $senderTransactions[0]['subtype']);
        $this->assertSame('expense', $senderTransactions[0]['analytics_bucket']);
        $this->assertSame('peer_transfer', $senderTransactions[0]['category_slug']);
        $this->assertTrue($senderTransactions[0]['editable_category']);
        $this->assertDatabaseHas('authorized_transactions', [
            'trx' => $trx,
        ]);
        $result = AuthorizedTransaction::query()->where('trx', $trx)->value('result');
        $this->assertIsArray($result);
        $this->assertSame($reference, $result['posting']['transfer_reference'] ?? null);
        $this->assertSame('posted', $result['posting']['status'] ?? null);
        $this->assertSame('send_money', $result['posting']['posting_type'] ?? null);

        Event::assertDispatched(WalletBalanceUpdated::class, fn (WalletBalanceUpdated $event): bool => $event->userId === $this->sender->id);
        Event::assertDispatched(WalletBalanceUpdated::class, fn (WalletBalanceUpdated $event): bool => $event->userId === $this->recipient->id);
    }

    #[Test]
    public function it_ignores_the_client_none_hint_and_requires_otp_when_policy_steps_up(): void
    {
        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000009902',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '1000.00',
            'verification_type' => 'none',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'otp');

        $trx = (string) $response->json('data.trx');
        $this->assertDatabaseHas('authorized_transactions', [
            'trx'               => $trx,
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
        ]);
        $this->assertDatabaseMissing('asset_transfers', [
            'reference' => $trx,
        ]);
    }

    #[Test]
    public function it_uses_pin_for_low_risk_send_money_when_the_sender_has_a_transaction_pin(): void
    {
        $this->sender->update(['transaction_pin' => '1234', 'transaction_pin_enabled' => true]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '25.00',
            'verification_type' => 'none',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'pin')
            ->assertJsonPath('data.code_sent_message', null);
    }

    #[Test]
    public function it_finalizes_low_risk_send_money_without_verification_when_the_sender_pin_exists_but_is_disabled(): void
    {
        $this->sender->update(['transaction_pin' => '1234', 'transaction_pin_enabled' => false]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000009905',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '25.00',
            'verification_type' => 'pin',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.next_step', 'none');
    }

    #[Test]
    public function it_steps_up_to_pin_when_the_sender_pin_exists_but_is_disabled(): void
    {
        $this->sender->update(['transaction_pin' => '1234', 'transaction_pin_enabled' => false]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000009906',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '1000.00',
            'verification_type' => 'none',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'pin');
    }

    #[Test]
    public function it_reuses_the_existing_send_money_result_after_idempotency_cache_loss(): void
    {
        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $idempotencyKey = '00000000-0000-0000-0000-000000009903';
        $payload = [
            'user'              => $this->recipient->email,
            'amount'            => '10.00',
            'verification_type' => 'sms',
            'note'              => 'Cache-loss replay',
        ];

        $first = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/send-money/store', $payload);

        $first->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.next_step', 'none');

        Cache::flush();

        $second = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/send-money/store', $payload);

        $second->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.next_step', 'none')
            ->assertJsonPath('data.trx', $first->json('data.trx'))
            ->assertJsonPath('data.reference', $first->json('data.reference'));

        $trx = (string) $first->json('data.trx');
        $reference = (string) $first->json('data.reference');

        $this->assertSame(1, AuthorizedTransaction::query()
            ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
            ->where('user_id', $this->sender->id)
            ->where('trx', $trx)
            ->count());

        $this->assertSame(1, AssetTransfer::query()
            ->where('reference', $reference)
            ->count());

        $this->assertDatabaseHas('account_balances', [
            'account_uuid' => $this->senderAccount->uuid,
            'asset_code'   => 'SZL',
            'balance'      => 499_000,
        ]);
        $this->assertDatabaseHas('account_balances', [
            'account_uuid' => $this->recipientAccount->uuid,
            'asset_code'   => 'SZL',
            'balance'      => 1_000,
        ]);
    }

    #[Test]
    public function it_replays_send_money_after_cache_loss_even_when_the_client_hint_changes_but_policy_stays_none(): void
    {
        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $idempotencyKey = '00000000-0000-0000-0000-000000009904';

        $first = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '10.00',
            'verification_type' => 'sms',
            'note'              => 'Hint drift replay',
        ]);

        $first->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.next_step', 'none');

        Cache::flush();

        $second = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '10.00',
            'verification_type' => 'none',
            'note'              => 'Hint drift replay',
        ]);

        $second->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.next_step', 'none')
            ->assertJsonPath('data.trx', $first->json('data.trx'))
            ->assertJsonPath('data.reference', $first->json('data.reference'));
    }

    #[Test]
    public function it_fails_closed_when_pre_execution_risk_hooks_block_a_none_send_money_finalization(): void
    {
        $provider = Mockery::mock(MoneyMovementRiskSignalProviderInterface::class);
        $provider->shouldReceive('evaluateInitiation')
            ->once()
            ->andReturn([
                'step_up' => false,
                'reason'  => null,
            ]);
        $provider->shouldReceive('evaluatePreExecution')
            ->once()
            ->andReturn([
                'allow'  => false,
                'reason' => 'pre_execution_blocked',
            ]);
        $this->app->instance(MoneyMovementRiskSignalProviderInterface::class, $provider);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000009905',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '10.00',
            'verification_type' => 'none',
            'note'              => 'Pre-execution hard block',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('remark', 'send_money')
            ->assertJsonPath('message.0', 'pre_execution_blocked');

        $this->assertDatabaseHas('authorized_transactions', [
            'remark'         => AuthorizedTransaction::REMARK_SEND_MONEY,
            'user_id'        => $this->sender->id,
            'status'         => AuthorizedTransaction::STATUS_PENDING,
            'failure_reason' => null,
        ]);
        $this->assertDatabaseCount('asset_transfers', 0);
    }

    #[Test]
    public function it_fails_closed_when_posting_creation_fails_after_the_transfer_handler_runs(): void
    {
        $postingService = $this->createMock(LedgerPostingService::class);
        $postingService->expects($this->once())
            ->method('createForAuthorizedTransaction')
            ->willThrowException(new RuntimeException('Simulated posting failure'));
        $this->app->instance(LedgerPostingService::class, $postingService);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $senderBefore = AccountBalance::query()
            ->where('account_uuid', $this->senderAccount->uuid)
            ->where('asset_code', 'SZL')
            ->value('balance');
        $recipientBefore = AccountBalance::query()
            ->where('account_uuid', $this->recipientAccount->uuid)
            ->where('asset_code', 'SZL')
            ->value('balance');

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000009907',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '10.00',
            'verification_type' => 'sms',
            'note'              => 'Posting failure boundary',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('remark', 'send_money')
            ->assertJsonPath('message.0', 'Simulated posting failure');

        $this->assertSame($senderBefore, AccountBalance::query()
            ->where('account_uuid', $this->senderAccount->uuid)
            ->where('asset_code', 'SZL')
            ->value('balance'));
        $this->assertSame($recipientBefore, AccountBalance::query()
            ->where('account_uuid', $this->recipientAccount->uuid)
            ->where('asset_code', 'SZL')
            ->value('balance'));

        $this->assertDatabaseHas('authorized_transactions', [
            'remark'         => AuthorizedTransaction::REMARK_SEND_MONEY,
            'user_id'        => $this->sender->id,
            'status'         => AuthorizedTransaction::STATUS_PENDING,
            'failure_reason' => null,
        ]);
        $txn = AuthorizedTransaction::query()
            ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
            ->where('user_id', $this->sender->id)
            ->latest('created_at')
            ->firstOrFail();

        $this->assertDatabaseMissing('asset_transfers', [
            'reference' => $txn->trx,
        ]);
        $this->assertDatabaseMissing('ledger_postings', [
            'authorized_transaction_trx' => $txn->trx,
        ]);
    }
}
