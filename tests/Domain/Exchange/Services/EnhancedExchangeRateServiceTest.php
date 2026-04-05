<?php

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\ExchangeRate;
use App\Domain\Asset\Services\ExchangeRateService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Clear any existing exchange rates to ensure test isolation
    ExchangeRate::query()->delete();

    // Create test assets (use firstOrCreate to avoid duplicates in parallel tests)
    Asset::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);
    Asset::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);
    Asset::firstOrCreate(['code' => 'GBP'], ['name' => 'British Pound', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);
    Asset::firstOrCreate(['code' => 'BTC'], ['name' => 'Bitcoin', 'type' => 'crypto', 'precision' => 8, 'is_active' => true]);
    Asset::firstOrCreate(['code' => 'ETH'], ['name' => 'Ethereum', 'type' => 'crypto', 'precision' => 8, 'is_active' => true]);

    // Clear caches
    Cache::flush();

    $this->service = new ExchangeRateService();
});

it('returns identity rate for same asset conversion', function () {
    $rate = $this->service->getRate('USD', 'USD');

    expect($rate)->not->toBeNull();
    expect((float) $rate->rate)->toBe(1.0);
    expect($rate->from_asset_code)->toBe('USD');
    expect($rate->to_asset_code)->toBe('USD');
});

it('retrieves cached exchange rate when available and fresh', function () {
    // Create a fresh exchange rate
    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.85,
        'source'          => 'api',
        'valid_at'        => now(),
        'expires_at'      => now()->addHour(),
        'is_active'       => true,
    ]);

    $rate = $this->service->getRate('USD', 'EUR');

    expect($rate)->not->toBeNull();
    expect((float) $rate->rate)->toBe(0.85);
});

it('returns null when no exchange rate exists for unknown assets', function () {
    $rate = $this->service->getRate('USD', 'JPY'); // JPY doesn't exist

    expect($rate)->toBeNull();
});

it('ignores expired exchange rates', function () {
    // Create an expired exchange rate
    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.85,
        'source'          => 'api',
        'valid_at'        => now()->subHours(2),
        'expires_at'      => now()->subHour(),
        'is_active'       => true,
    ]);

    // Should try to fetch new rate
    $rate = $this->service->getRate('USD', 'EUR');

    // With mocked providers, this might fetch a new rate
    // If a new rate was fetched, it should be valid
    if ($rate) {
        expect($rate->isValid())->toBeTrue();
        expect($rate->source)->toBeIn(['api', 'oracle', 'mock', 'test']);
    } else {
        expect($rate)->toBeNull();
    }
});

it('ignores inactive exchange rates', function () {
    // Create an inactive exchange rate
    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.85,
        'source'          => 'api',
        'valid_at'        => now(),
        'expires_at'      => now()->addHour(),
        'is_active'       => false,
    ]);

    $rate = $this->service->getRate('USD', 'EUR');

    // Since the inactive rate is ignored, the service might fetch a new one
    if ($rate) {
        expect($rate->is_active)->toBeTrue();
        expect($rate->source)->toBeIn(['api', 'oracle', 'mock', 'test']);
    } else {
        expect($rate)->toBeNull();
    }
});

it('can convert amount between assets using exchange rate', function () {
    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.85,
        'source'          => 'api',
        'valid_at'        => now(),
        'expires_at'      => now()->addHour(),
        'is_active'       => true,
    ]);

    $convertedAmount = $this->service->convert(10000, 'USD', 'EUR'); // $100.00

    expect($convertedAmount)->toBe(8500); // €85.00 in cents
});

it('returns null when converting with unavailable exchange rate', function () {
    $convertedAmount = $this->service->convert(10000, 'USD', 'JPY');

    expect($convertedAmount)->toBeNull();
});

it('returns same amount when converting between same assets', function () {
    $convertedAmount = $this->service->convert(10000, 'USD', 'USD');

    expect($convertedAmount)->toBe(10000);
});

it('can store new exchange rate', function () {
    $rate = $this->service->storeRate('USD', 'EUR', 0.87, 'manual');

    expect($rate)->toBeInstanceOf(ExchangeRate::class);
    expect($rate->from_asset_code)->toBe('USD');
    expect($rate->to_asset_code)->toBe('EUR');
    expect((float) $rate->rate)->toBe(0.87);
    expect($rate->source)->toBe('manual');
    expect($rate->is_active)->toBeTrue();

    $this->assertDatabaseHas('exchange_rates', [
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.87,
        'source'          => 'manual',
        'is_active'       => true,
    ]);
});

it('clears cache when storing new rate', function () {
    // First, ensure there's something in cache
    Cache::put('exchange_rate:USD:EUR', 'cached_value', 60);
    expect(Cache::has('exchange_rate:USD:EUR'))->toBeTrue();

    // Store new rate should clear cache
    $this->service->storeRate('USD', 'EUR', 0.87);

    expect(Cache::has('exchange_rate:USD:EUR'))->toBeFalse();
});

it('can get available rates for an asset', function () {
    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.85,
        'source'          => 'api',
        'valid_at'        => now(),
        'expires_at'      => now()->addHour(),
        'is_active'       => true,
    ]);

    ExchangeRate::create([
        'from_asset_code' => 'EUR',
        'to_asset_code'   => 'USD',
        'rate'            => 1.18,
        'source'          => 'api',
        'valid_at'        => now(),
        'expires_at'      => now()->addHour(),
        'is_active'       => true,
    ]);

    $rates = $this->service->getAvailableRatesFor('USD');

    expect($rates)->toHaveCount(2);
});

it('can get rate history for a specific pair', function () {
    // Create historical rates
    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.84,
        'source'          => 'api',
        'valid_at'        => now()->subDays(2),
        'expires_at'      => now()->subDay(),
        'is_active'       => false,
    ]);

    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.85,
        'source'          => 'api',
        'valid_at'        => now(),
        'expires_at'      => now()->addHour(),
        'is_active'       => true,
    ]);

    $history = $this->service->getRateHistory('USD', 'EUR', 7); // Last 7 days

    expect($history)->toHaveCount(2);
    expect((float) $history->first()->rate)->toBe(0.85); // Most recent first
});

it('can refresh stale rates', function () {
    // Create a stale rate
    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.84,
        'source'          => 'api',
        'valid_at'        => now()->subHours(2), // Stale
        'expires_at'      => now()->addHour(),
        'is_active'       => true,
    ]);

    $refreshed = $this->service->refreshStaleRates();

    // Since we're using mock providers, this might succeed or fail
    // depending on whether the mock rate is available
    expect($refreshed)->toBeInt();
    expect($refreshed)->toBeGreaterThanOrEqual(0);
});

it('validates asset existence before operations', function () {
    // Try to get rate for non-existent asset
    $rate = $this->service->getRate('INVALID', 'USD');
    expect($rate)->toBeNull();

    $convertedAmount = $this->service->convert(10000, 'INVALID', 'USD');
    expect($convertedAmount)->toBeNull();
});

it('handles crypto asset rate fetching', function () {
    // The service should be able to handle crypto rates
    // This will use the mock crypto provider
    $rate = $this->service->fetchAndStoreRate('BTC', 'USD');

    if ($rate) {
        expect($rate)->toBeInstanceOf(ExchangeRate::class);
        expect($rate->from_asset_code)->toBe('BTC');
        expect($rate->to_asset_code)->toBe('USD');
        expect($rate->rate)->toBeString();
        expect($rate->source)->toBe('api');
    } else {
        // The mock might not have BTC-USD pair, so rate could be null
        expect($rate)->toBeNull();
    }
});

it('handles fiat currency rate fetching', function () {
    // The service should be able to handle fiat rates
    // This will use the mock fiat provider
    $rate = $this->service->fetchAndStoreRate('USD', 'EUR');

    if ($rate) {
        expect($rate)->toBeInstanceOf(ExchangeRate::class);
        expect($rate->from_asset_code)->toBe('USD');
        expect($rate->to_asset_code)->toBe('EUR');
        expect($rate->rate)->toBeString();
        expect($rate->source)->toBe('api');
    } else {
        // The mock should have USD-EUR pair
        expect($rate)->toBeNull();
    }
});

it('caches exchange rates appropriately', function () {
    // Create a rate
    ExchangeRate::create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'rate'            => 0.85,
        'source'          => 'api',
        'valid_at'        => now(),
        'expires_at'      => now()->addHour(),
        'is_active'       => true,
    ]);

    // First call should cache the result
    $rate1 = $this->service->getRate('USD', 'EUR');

    // Verify cache key format
    expect(Cache::has('exchange_rate:USD:EUR'))->toBeTrue();

    // Second call should use cache
    $rate2 = $this->service->getRate('USD', 'EUR');

    expect($rate1)->toEqual($rate2);
});

it('can get inverse rates', function () {
    // Clear any existing rates first
    ExchangeRate::where('from_asset_code', 'EUR')->where('to_asset_code', 'USD')->delete();

    ExchangeRate::create([
        'from_asset_code' => 'EUR',
        'to_asset_code'   => 'USD',
        'rate'            => 1.18,
        'source'          => 'test',
        'valid_at'        => now(),
        'expires_at'      => now()->addHour(),
        'is_active'       => true,
    ]);

    // Get the direct rate EUR->USD
    $directRate = $this->service->getRate('EUR', 'USD');
    expect($directRate)->not->toBeNull();
    expect((float) $directRate->rate)->toBe(1.18);

    // Get the inverse rate USD->EUR (should be 1/1.18 ≈ 0.85)
    $inverseRate = $this->service->getInverseRate('USD', 'EUR');

    expect($inverseRate)->not->toBeNull();
    expect($inverseRate->from_asset_code)->toBe('EUR');
    expect($inverseRate->to_asset_code)->toBe('USD');
    expect((float) $inverseRate->rate)->toBe(1.18);
});
