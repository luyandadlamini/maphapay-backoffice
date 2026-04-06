<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\UserResource\Pages\ViewUser;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->admin->givePermissionTo('view-users');

    $this->customer = User::factory()->create([
        'email'  => 'customer@example.com',
        'mobile' => '761000000',
    ]);
});

it('contains tabbed relation managers for customer 360', function () {
    Livewire\Livewire::actingAs($this->admin)
        ->test(ViewUser::class, ['record' => $this->customer->uuid])
        ->assertSuccessful()
        ->assertSee('Bank Accounts')
        ->assertSee('Referrals');
});

it('can reset 2FA for a customer if authorized', function () {
    $this->admin->givePermissionTo('reset-user-password');
    $this->customer->update(['two_factor_secret' => 'SECRET']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ViewUser::class, ['record' => $this->customer->uuid])
        ->callAction('reset2fa', data: ['reason' => 'Test reset']);

    $this->assertNull($this->customer->fresh()->two_factor_secret);
});
