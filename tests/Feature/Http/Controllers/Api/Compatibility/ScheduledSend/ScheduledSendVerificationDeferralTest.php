<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\ScheduledSend;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\ScheduledSend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

/**
 * Scheduled sends must not transfer on OTP/PIN — only after {@see \App\Console\Commands\ExecuteScheduledSends}.
 */
#[Large]
final class ScheduledSendVerificationDeferralTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maphapay_migration.enable_scheduled_send' => true,
            'maphapay_migration.enable_verification'   => true,
        ]);

        $this->sender = User::factory()->create();
        $this->recipient = User::factory()->create();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        Account::factory()->create([
            'user_uuid' => $this->sender->uuid,
            'frozen'    => false,
        ]);

        Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'frozen'    => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_otp_verify_authorizes_only_transaction_stays_pending_until_command(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-28 10:00:00'));

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $scheduledFor = Carbon::now()->addHour()->toIso8601String();

        $store = $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '5.00',
            'scheduled_for'     => $scheduledFor,
            'verification_type' => 'sms',
        ]);
        $store->assertOk();
        $trx = (string) $store->json('data.trx');

        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();
        $txn->update([
            'otp_hash'       => Hash::make('654321'),
            'otp_sent_at'    => now(),
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        $verify = $this->postJson('/api/verification-process/verify/otp', [
            'trx'    => $trx,
            'otp'    => '654321',
            'remark' => 'scheduled_send',
        ]);

        $verify->assertOk()
            ->assertJsonPath('data.scheduled', true);

        $txn->refresh();
        $this->assertSame(AuthorizedTransaction::STATUS_PENDING, $txn->status);
        $this->assertNotNull($txn->verification_confirmed_at);

        $send = ScheduledSend::query()->where('trx', $trx)->firstOrFail();
        $this->assertSame(ScheduledSend::STATUS_PENDING, $send->status);
    }

    #[Test]
    public function test_command_transfers_after_scheduled_time_when_otp_already_confirmed(): void
    {
        $this->mock(WalletOperationsService::class, function ($mock): void {
            $mock->shouldReceive('transfer')
                ->once()
                ->andReturn('scheduled-transfer-id');
        });

        Carbon::setTestNow(Carbon::parse('2026-03-28 10:00:00'));

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $scheduledFor = Carbon::now()->addHour()->toIso8601String();

        $store = $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '3.00',
            'scheduled_for'     => $scheduledFor,
            'verification_type' => 'sms',
        ]);
        $store->assertOk();
        $trx = (string) $store->json('data.trx');

        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();
        $txn->update([
            'otp_hash'       => Hash::make('111222'),
            'otp_sent_at'    => now(),
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/verification-process/verify/otp', [
            'trx'    => $trx,
            'otp'    => '111222',
            'remark' => 'scheduled_send',
        ])->assertOk();

        // Default authorized-transaction TTL is 60 minutes; scheduled execution is later.
        AuthorizedTransaction::query()->where('trx', $trx)->update([
            'expires_at' => now()->addDay(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-28 11:05:00'));

        $this->assertSame(0, Artisan::call('scheduled-sends:execute'));

        $txn->refresh();
        $this->assertSame(AuthorizedTransaction::STATUS_COMPLETED, $txn->status);

        $send = ScheduledSend::query()->where('trx', $trx)->firstOrFail();
        $this->assertSame(ScheduledSend::STATUS_EXECUTED, $send->status);
    }

    #[Test]
    public function test_pin_verify_authorizes_only_transaction_stays_pending(): void
    {
        // The User model casts 'transaction_pin' via 'hashed', so Eloquent hashes on assignment;
        // passing the plaintext here is intentional — this is the raw PIN the test will POST.
        $this->sender->update(['transaction_pin' => '9876', 'transaction_pin_enabled' => true]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $scheduledFor = Carbon::now()->addDay()->toIso8601String();

        $store = $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '2.00',
            'scheduled_for'     => $scheduledFor,
            'verification_type' => 'pin',
        ]);
        $store->assertOk()->assertJsonPath('data.next_step', 'pin');
        $trx = (string) $store->json('data.trx');

        $verify = $this->postJson('/api/verification-process/verify/pin', [
            'trx'    => $trx,
            'pin'    => '9876',
            'remark' => 'scheduled_send',
        ]);

        $verify->assertOk()->assertJsonPath('data.scheduled', true);

        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();
        $this->assertSame(AuthorizedTransaction::STATUS_PENDING, $txn->status);
        $this->assertNotNull($txn->verification_confirmed_at);
    }
}
