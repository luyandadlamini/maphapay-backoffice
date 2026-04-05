<?php

use App\Domain\Asset\Models\Asset;
use App\Domain\Basket\Models\BasketAsset;
use App\Domain\Basket\Models\BasketValue;
use App\Models\User;

beforeEach(function () {
    // Create test assets (use firstOrCreate to avoid duplicates in parallel tests)
    Asset::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'type' => 'fiat', 'precision' => 2]);
    Asset::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'type' => 'fiat', 'precision' => 2]);
    Asset::firstOrCreate(['code' => 'GBP'], ['name' => 'British Pound', 'type' => 'fiat', 'precision' => 2]);
});

it('can create a basket asset', function () {
    $basket = BasketAsset::create([
        'code'                => 'STABLE_BASKET',
        'name'                => 'Stable Currency Basket',
        'description'         => 'A basket of stable fiat currencies',
        'type'                => 'fixed',
        'rebalance_frequency' => 'never',
        'is_active'           => true,
    ]);

    expect($basket)->toBeInstanceOf(BasketAsset::class);
    expect($basket->code)->toBe('STABLE_BASKET');
    expect($basket->type)->toBe('fixed');
    expect($basket->is_active)->toBeTrue();
});

it('can add components to a basket', function () {
    $basket = BasketAsset::create([
        'code' => 'STABLE_BASKET',
        'name' => 'Stable Currency Basket',
    ]);

    $components = [
        ['asset_code' => 'USD', 'weight' => 40.0],
        ['asset_code' => 'EUR', 'weight' => 35.0],
        ['asset_code' => 'GBP', 'weight' => 25.0],
    ];

    foreach ($components as $componentData) {
        $basket->components()->create($componentData);
    }

    expect($basket->components)->toHaveCount(3);
    expect($basket->components->sum('weight'))->toBe(100.0);
});

it('validates that component weights sum to 100', function () {
    $basket = BasketAsset::create([
        'code' => 'STABLE_BASKET',
        'name' => 'Stable Currency Basket',
    ]);

    $basket->components()->create(['asset_code' => 'USD', 'weight' => 40.0]);
    $basket->components()->create(['asset_code' => 'EUR', 'weight' => 35.0]);
    $basket->components()->create(['asset_code' => 'GBP', 'weight' => 25.0]);

    expect($basket->validateWeights())->toBeTrue();

    // Add another component to break the 100% rule (using a different asset)
    $basket->components()->create(['asset_code' => 'BTC', 'weight' => 10.0]);

    expect($basket->validateWeights())->toBeFalse();
});

it('can determine if a basket needs rebalancing', function () {
    // Fixed basket should never need rebalancing
    $fixedBasket = BasketAsset::create([
        'code'                => 'FIXED_BASKET',
        'name'                => 'Fixed Basket',
        'type'                => 'fixed',
        'rebalance_frequency' => 'daily',
    ]);

    expect($fixedBasket->needsRebalancing())->toBeFalse();

    // Dynamic basket with never frequency should not need rebalancing
    $neverBasket = BasketAsset::create([
        'code'                => 'NEVER_BASKET',
        'name'                => 'Never Rebalance Basket',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'never',
    ]);

    expect($neverBasket->needsRebalancing())->toBeFalse();

    // Dynamic basket that has never been rebalanced should need rebalancing
    $newBasket = BasketAsset::create([
        'code'                => 'NEW_BASKET',
        'name'                => 'New Dynamic Basket',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'daily',
        'last_rebalanced_at'  => null,
    ]);

    expect($newBasket->needsRebalancing())->toBeTrue();

    // Dynamic basket rebalanced yesterday should need rebalancing
    $oldBasket = BasketAsset::create([
        'code'                => 'OLD_BASKET',
        'name'                => 'Old Dynamic Basket',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'daily',
        'last_rebalanced_at'  => now()->subDays(2),
    ]);

    expect($oldBasket->needsRebalancing())->toBeTrue();

    // Dynamic basket rebalanced today should not need rebalancing
    $recentBasket = BasketAsset::create([
        'code'                => 'RECENT_BASKET',
        'name'                => 'Recently Rebalanced Basket',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'daily',
        'last_rebalanced_at'  => now()->subHours(2),
    ]);

    expect($recentBasket->needsRebalancing())->toBeFalse();
});

it('can convert basket to asset for compatibility', function () {
    $basket = BasketAsset::create([
        'code'      => 'STABLE_BASKET',
        'name'      => 'Stable Currency Basket',
        'is_active' => true,
    ]);

    $asset = $basket->toAsset();

    expect($asset)->toBeInstanceOf(Asset::class);
    expect($asset->code)->toBe('STABLE_BASKET');
    expect($asset->name)->toBe('Stable Currency Basket');
    expect($asset->type)->toBe('custom');
    expect($asset->is_basket)->toBeTrue();
    expect($asset->metadata['basket_id'])->toBe($basket->id);
});

it('can retrieve latest basket value', function () {
    $basket = BasketAsset::create([
        'code' => 'STABLE_BASKET',
        'name' => 'Stable Currency Basket',
    ]);

    // No values yet
    expect($basket->latestValue()->first())->toBeNull();

    // Create some values
    BasketValue::create([
        'basket_asset_code' => 'STABLE_BASKET',
        'value'             => 1.0,
        'calculated_at'     => now()->subHour(),
    ]);

    $latestValue = BasketValue::create([
        'basket_asset_code' => 'STABLE_BASKET',
        'value'             => 1.05,
        'calculated_at'     => now(),
    ]);

    expect($basket->latestValue->id)->toBe($latestValue->id);
    expect($basket->latestValue->value)->toBe(1.05);
});

it('can scope active baskets', function () {
    BasketAsset::create([
        'code'      => 'ACTIVE_BASKET',
        'name'      => 'Active Basket',
        'is_active' => true,
    ]);

    BasketAsset::create([
        'code'      => 'INACTIVE_BASKET',
        'name'      => 'Inactive Basket',
        'is_active' => false,
    ]);

    $activeBaskets = BasketAsset::active()->get();

    expect($activeBaskets)->toHaveCount(1);
    expect($activeBaskets->first()->code)->toBe('ACTIVE_BASKET');
});

it('can scope baskets that need rebalancing', function () {
    // Create baskets that don't need rebalancing
    BasketAsset::create([
        'code'                => 'FIXED',
        'name'                => 'Fixed',
        'type'                => 'fixed',
        'rebalance_frequency' => 'daily',
    ]);

    BasketAsset::create([
        'code'                => 'NEVER',
        'name'                => 'Never',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'never',
    ]);

    BasketAsset::create([
        'code'                => 'RECENT',
        'name'                => 'Recent',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'daily',
        'last_rebalanced_at'  => now()->subHours(2),
    ]);

    // Create baskets that need rebalancing
    BasketAsset::create([
        'code'                => 'NEEDS_DAILY',
        'name'                => 'Needs Daily',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'daily',
        'last_rebalanced_at'  => now()->subDays(2),
    ]);

    BasketAsset::create([
        'code'                => 'NEEDS_WEEKLY',
        'name'                => 'Needs Weekly',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'weekly',
        'last_rebalanced_at'  => now()->subWeeks(2),
    ]);

    BasketAsset::create([
        'code'                => 'NEVER_DONE',
        'name'                => 'Never Done',
        'type'                => 'dynamic',
        'rebalance_frequency' => 'monthly',
        'last_rebalanced_at'  => null,
    ]);

    $needsRebalancing = BasketAsset::query()->needsRebalancing()->get();

    expect($needsRebalancing)->toHaveCount(3);
    expect($needsRebalancing->pluck('code')->toArray())->toContain('NEEDS_DAILY', 'NEEDS_WEEKLY', 'NEVER_DONE');
});

it('has a relationship with its creator', function () {
    $user = User::factory()->create();

    $basket = BasketAsset::create([
        'code'       => 'USER_BASKET',
        'name'       => 'User Created Basket',
        'created_by' => $user->uuid,
    ]);

    expect($basket->creator)->toBeInstanceOf(User::class);
    expect($basket->creator->uuid)->toBe($user->uuid);
});

it('can calculate basket value without exchange rate service', function () {
    $basket = BasketAsset::create([
        'code' => 'USD_ONLY',
        'name' => 'USD Only Basket',
    ]);

    $basket->components()->create([
        'asset_code' => 'USD',
        'weight'     => 100.0,
    ]);

    // Since it's 100% USD, the value should be 1.0
    expect($basket->calculateValue())->toBe(1.0);
});
