<?php

declare(strict_types=1);

use App\Domain\Asset\Events\ExchangeRateUpdated;
use App\Domain\Asset\Models\ExchangeRate;
use App\Filament\Admin\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use App\Models\User;
use Illuminate\Support\Facades\Event;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament\Facades\Filament::getPanel('admin');
    Filament\Facades\Filament::setCurrentPanel($panel);
    Filament\Facades\Filament::setServingStatus(true);
    $panel->boot();
});

it('operations-l2 cannot directly edit exchange rate', function () {
    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $this->actingAs($ops);

    $rate = ExchangeRate::factory()->create(['rate' => 15.5000000000]);

    livewire(ListExchangeRates::class)
        ->assertTableActionDoesNotExist('edit');
});

it('user with manage-feature-flags can see set rate action', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $admin->givePermissionTo('manage-feature-flags');
    $this->actingAs($admin);

    $rate = ExchangeRate::factory()->create(['rate' => 15.5000000000]);

    livewire(ListExchangeRates::class)
        ->assertTableActionExists('setRate');
});

it('set rate action updates rate and fires event', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $admin->givePermissionTo('manage-feature-flags');
    $this->actingAs($admin);

    $rate = ExchangeRate::factory()->create(['rate' => 15.5000000000]);

    Event::fake();

    livewire(ListExchangeRates::class)
        ->callTableAction('setRate', $rate, [
            'rate'   => 16.2500000000,
            'reason' => 'Market adjustment per treasury review',
        ]);

    expect($rate->fresh()->rate)->toBe('16.2500000000');

    Event::assertDispatched(ExchangeRateUpdated::class, function ($event) {
        return $event->oldRate === 15.5
            && $event->newRate === 16.25
            && $event->source === 'manual';
    });
});
