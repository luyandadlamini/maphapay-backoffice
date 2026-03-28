<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Models\ScheduledSend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Scheduled send executor command (MaphaPay compatibility).
 *
 * #[Large] is required: first-run sqlite migrations exceed the default 10s PHPUnit time limit.
 */
#[Large]
final class ExecuteScheduledSendsTest extends TestCase
{
    /**
     * @return array{0: User, 1: User, 2: ScheduledSend, 3: AuthorizedTransaction}
     */
    private static function makeFixture(Carbon $scheduledFor): array
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        $fromAccount = Account::factory()->create([
            'user_uuid' => $sender->uuid,
            'frozen'    => false,
        ]);

        $toAccount = Account::factory()->create([
            'user_uuid' => $recipient->uuid,
            'frozen'    => false,
        ]);

        $scheduledSendId = (string) Str::uuid();
        $trx = 'TRX-' . strtoupper(Str::random(8));

        $scheduledSend = ScheduledSend::query()->create([
            'id'                => $scheduledSendId,
            'sender_user_id'    => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'scheduled_for'     => $scheduledFor,
            'status'            => ScheduledSend::STATUS_PENDING,
            'trx'               => $trx,
        ]);

        $authorizedTransaction = AuthorizedTransaction::query()->create([
            'user_id' => $sender->id,
            'remark'  => AuthorizedTransaction::REMARK_SCHEDULED_SEND,
            'trx'     => $trx,
            'payload' => [
                'scheduled_send_id' => $scheduledSendId,
                'from_account_uuid' => $fromAccount->uuid,
                'to_account_uuid'   => $toAccount->uuid,
                'amount'            => '10.00',
                'asset_code'        => 'SZL',
                'note'              => '',
                'scheduled_at'      => $scheduledFor->toIso8601String(),
            ],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
            'expires_at'        => now()->addHour(),
        ]);

        return [$sender, $recipient, $scheduledSend, $authorizedTransaction];
    }

    private static function assertScheduledSendStatus(ScheduledSend $send, string $expectedStatus): void
    {
        $fresh = $send->fresh();
        self::assertNotNull($fresh);
        self::assertSame($expectedStatus, $fresh->status);
    }

    #[Test]
    public function test_executes_past_due_pending_scheduled_sends(): void
    {
        [, , $scheduledSend] = self::makeFixture(Carbon::now()->subMinute());

        $manager = Mockery::mock(AuthorizedTransactionManager::class);
        $manager->shouldReceive('finalize')
            ->once()
            ->with(Mockery::on(fn (AuthorizedTransaction $t): bool => $t->trx === $scheduledSend->trx))
            ->andReturn(['ok' => true]);

        $this->app->instance(AuthorizedTransactionManager::class, $manager);

        $this->assertSame(0, Artisan::call('scheduled-sends:execute'));

        self::assertScheduledSendStatus($scheduledSend, ScheduledSend::STATUS_EXECUTED);
    }

    #[Test]
    public function test_skips_future_scheduled_sends(): void
    {
        [, , $scheduledSend] = self::makeFixture(Carbon::now()->addDay());

        $manager = Mockery::mock(AuthorizedTransactionManager::class);
        $manager->shouldNotReceive('finalize');

        $this->app->instance(AuthorizedTransactionManager::class, $manager);

        $this->assertSame(0, Artisan::call('scheduled-sends:execute'));

        self::assertScheduledSendStatus($scheduledSend, ScheduledSend::STATUS_PENDING);
    }

    #[Test]
    public function test_skips_non_pending_scheduled_sends(): void
    {
        [, , $scheduledSend] = self::makeFixture(Carbon::now()->subMinute());
        $scheduledSend->update(['status' => ScheduledSend::STATUS_CANCELLED]);

        $manager = Mockery::mock(AuthorizedTransactionManager::class);
        $manager->shouldNotReceive('finalize');

        $this->app->instance(AuthorizedTransactionManager::class, $manager);

        $this->assertSame(0, Artisan::call('scheduled-sends:execute'));

        self::assertScheduledSendStatus($scheduledSend, ScheduledSend::STATUS_CANCELLED);
    }

    #[Test]
    public function test_marks_failed_and_logs_on_finalize_exception(): void
    {
        Log::spy();

        [, , $scheduledSend] = self::makeFixture(Carbon::now()->subMinute());

        $manager = Mockery::mock(AuthorizedTransactionManager::class);
        $manager->shouldReceive('finalize')
            ->once()
            ->andThrow(new RuntimeException('wallet exploded'));

        $this->app->instance(AuthorizedTransactionManager::class, $manager);

        $this->assertSame(0, Artisan::call('scheduled-sends:execute'));

        self::assertScheduledSendStatus($scheduledSend, ScheduledSend::STATUS_FAILED);

        $log = Log::getFacadeRoot();
        if (! $log instanceof MockInterface) {
            throw new RuntimeException('Expected Log facade root to be a Mockery spy.');
        }

        $log->shouldHaveReceived('error')
            ->once()
            ->with('ExecuteScheduledSends: finalize failed', Mockery::subset([
                'scheduled_send_id' => $scheduledSend->id,
                'trx'               => $scheduledSend->trx,
                'message'           => 'wallet exploded',
                'exception'         => RuntimeException::class,
            ]));
    }
}
