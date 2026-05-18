<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Domain\Wallet\Contracts\ProviderSettler;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\Emali\EmaliAdapter;
use App\Domain\Wallet\Services\MoneySettlerService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

final class ReconcileWalletProviderTransactionsTest extends TestCase
{
    public function test_reconciles_aged_pending_row_via_status_poll(): void
    {
        WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'rec-1',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 10_000,
            'user_uuid'           => '11111111-2222-3333-4444-555555555555',
        ]);
        WalletProviderTransaction::query()->latest('id')->limit(1)->update([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        // Stub the adapter so status() returns SUCCESSFUL.
        $adapter = Mockery::mock(WalletProviderAdapter::class);
        $adapter->shouldReceive('providerId')->andReturn('emali_eswatini_mobile');
        $adapter->shouldReceive('status')
            ->once()
            ->with('rec-1')
            ->andReturn(new WalletMovementStatus(
                providerRequestId: 'rec-1',
                status: WalletMovementStatus::STATUS_SUCCESSFUL,
                failureReason: null,
                settledAt: time(),
            ));
        $this->app->instance(EmaliAdapter::class, $adapter);

        // Real dispatcher with a spy settler — proves the command routes
        // outcomes through MoneySettlerService correctly.
        $spy = $this->spySettler('emali_eswatini_mobile');
        $this->app->instance(MoneySettlerService::class, new MoneySettlerService([$spy]));

        $exit = Artisan::call('wallet:reconcile', ['--min-age' => 5]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('settled=1', Artisan::output());
        $this->assertSame(1, $spy->calls);
        $this->assertSame('rec-1', $spy->lastRequestId);
        $this->assertSame('SUCCESSFUL', $spy->lastOutcome);
    }

    public function test_skips_rows_still_pending_remotely(): void
    {
        WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'rec-2',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 1_000,
            'user_uuid'           => '11111111-2222-3333-4444-555555555555',
        ]);
        WalletProviderTransaction::query()->latest('id')->limit(1)->update([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $adapter = Mockery::mock(WalletProviderAdapter::class);
        $adapter->shouldReceive('providerId')->andReturn('emali_eswatini_mobile');
        $adapter->shouldReceive('status')->once()->andReturn(new WalletMovementStatus(
            providerRequestId: 'rec-2',
            status: WalletMovementStatus::STATUS_PENDING,
            failureReason: null,
            settledAt: null,
        ));
        $this->app->instance(EmaliAdapter::class, $adapter);

        $spy = $this->spySettler('emali_eswatini_mobile');
        $this->app->instance(MoneySettlerService::class, new MoneySettlerService([$spy]));

        Artisan::call('wallet:reconcile', ['--min-age' => 5]);

        $this->assertStringContainsString('still_pending=1', Artisan::output());
    }

    public function test_skips_recent_rows_within_min_age_window(): void
    {
        WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'rec-3-recent',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 1_000,
            'user_uuid'           => '11111111-2222-3333-4444-555555555555',
        ]);
        // Recent row — should not be picked up by min-age=15.

        $adapter = Mockery::mock(WalletProviderAdapter::class);
        $adapter->shouldNotReceive('status');
        $this->app->instance(EmaliAdapter::class, $adapter);

        Artisan::call('wallet:reconcile', ['--min-age' => 15]);

        $this->assertStringContainsString('No pending wallet provider transactions to reconcile.', Artisan::output());
    }

    public function test_dry_run_does_not_call_settler(): void
    {
        WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'rec-dry',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 1_000,
            'user_uuid'           => '11111111-2222-3333-4444-555555555555',
        ]);
        WalletProviderTransaction::query()->latest('id')->limit(1)->update([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $adapter = Mockery::mock(WalletProviderAdapter::class);
        $adapter->shouldReceive('providerId')->andReturn('emali_eswatini_mobile');
        $adapter->shouldReceive('status')->once()->andReturn(new WalletMovementStatus(
            providerRequestId: 'rec-dry',
            status: WalletMovementStatus::STATUS_SUCCESSFUL,
            failureReason: null,
            settledAt: time(),
        ));
        $this->app->instance(EmaliAdapter::class, $adapter);

        $spy = $this->spySettler('emali_eswatini_mobile');
        $this->app->instance(MoneySettlerService::class, new MoneySettlerService([$spy]));

        Artisan::call('wallet:reconcile', ['--min-age' => 5, '--dry-run' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('[dry-run] would settle', $output);
        $this->assertStringContainsString('settled=1', $output);
        $this->assertSame(0, $spy->calls, 'dry-run must not invoke the settler');
    }

    private function spySettler(string $providerId): object
    {
        return new class ($providerId) implements ProviderSettler {
            public int $calls = 0;

            public string $lastRequestId = '';

            public string $lastOutcome = '';

            public function __construct(private readonly string $providerId)
            {
            }

            public function providerId(): string
            {
                return $this->providerId;
            }

            /**
             * @param  array<string, mixed>  $payload
             */
            public function settle(string $providerRequestId, string $outcome, array $payload): void
            {
                $this->calls++;
                $this->lastRequestId = $providerRequestId;
                $this->lastOutcome = $outcome;
            }
        };
    }
}
