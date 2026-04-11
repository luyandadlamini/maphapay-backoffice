<?php

declare(strict_types=1);

use App\Domain\Asset\Events\ExchangeRateUpdated;
use App\Domain\Asset\Models\ExchangeRate;
use App\Filament\Admin\Resources\ExchangeRateResource;
use App\Filament\Admin\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use App\Models\AdminActionApprovalRequest;
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

it('operations-l2 cannot access exchange rate management', function () {
    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $this->actingAs($ops);

    expect(ExchangeRateResource::canViewAny())->toBeFalse();
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

it('set rate action submits an approval request instead of mutating immediately', function () {
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
        ])
        ->assertHasNoTableActionErrors();

    expect($rate->fresh()->rate)->toBe('15.5000000000');

    Event::assertNotDispatched(ExchangeRateUpdated::class);

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.exchange_rates.set_rate')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('pending')
        ->and($request->payload['requested_rate'] ?? null)->toBe('16.2500000000');
});
