<?php

declare(strict_types=1);

use App\Domain\Asset\Models\Asset;
use App\Domain\Wallet\Mock\MockWalletStore;
use App\Filament\Admin\Pages\MockWalletLab;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line TestCase gains this helper from Tests\Traits\InteractsWithFilament. */
    $this->setUpFilamentWithAuth();
    Permission::firstOrCreate(['name' => 'view-transactions', 'guard_name' => 'web']);
    $this->adminUser?->givePermissionTo('view-transactions');

    config([
        'wallet_mocks.enabled'                                                    => true,
        'wallet_mocks.providers.emali_eswatini_mobile.currency'                   => 'SZL',
        'wallet_mocks.providers.emali_eswatini_mobile.default_seed_balance_minor' => 0,
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

    $keys = Redis::keys('mock:wallet:*');
    if (is_array($keys) && $keys !== []) {
        Redis::del(...array_map(fn (string $key): string => strip_redis_prefix($key), $keys));
    }
});

test('mock wallet lab funds an external provider account from Filament', function (): void {
    Livewire::test(MockWalletLab::class)
        ->set('data.provider_id', 'emali_eswatini_mobile')
        ->set('data.account_ref', '26876000001')
        ->set('data.amount', '123.45')
        ->set('data.currency', 'SZL')
        ->set('data.reset', false)
        ->call('fundAccount')
        ->assertSet('balance.account_ref', '26876000001')
        ->assertSet('balance.balance_minor', 12_345)
        ->assertSee('26876000001')
        ->assertSee('123.45 SZL');

    expect((new MockWalletStore())->getBalance('emali_eswatini_mobile', '26876000001'))->toBe(12_345);
});

test('mock wallet lab can reset a mock account balance', function (): void {
    $store = new MockWalletStore();
    $store->setBalance('emali_eswatini_mobile', '26876000001', 50_000, 'SZL');

    Livewire::test(MockWalletLab::class)
        ->set('data.provider_id', 'emali_eswatini_mobile')
        ->set('data.account_ref', '26876000001')
        ->set('data.amount', '5.00')
        ->set('data.currency', 'SZL')
        ->set('data.reset', true)
        ->call('fundAccount')
        ->assertSet('balance.balance_minor', 500)
        ->assertSee('5.00 SZL');
});

test('mock wallet lab looks up the latest balance without changing it', function (): void {
    $store = new MockWalletStore();
    $store->setBalance('emali_eswatini_mobile', '26876000001', 7_700, 'SZL');

    Livewire::test(MockWalletLab::class)
        ->set('data.provider_id', 'emali_eswatini_mobile')
        ->set('data.account_ref', '26876000001')
        ->call('lookupBalance')
        ->assertSet('balance.balance_minor', 7_700)
        ->assertSee('77.00 SZL');
});

function strip_redis_prefix(string $key): string
{
    $prefix = config('database.redis.options.prefix');

    return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
        ? substr($key, strlen($prefix))
        : $key;
}
