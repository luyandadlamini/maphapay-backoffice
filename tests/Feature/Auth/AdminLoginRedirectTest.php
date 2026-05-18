<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('redirects admin panel users to the Filament admin portal after login', function (): void {
    $user = User::factory()->create([
        'email'    => 'admin-login-redirect-' . str()->uuid()->toString() . '@example.com',
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('super-admin');

    $response = $this->post('/login', [
        'email'    => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/admin');
});

it('does not send admin panel users back to a stale intended customer dashboard after login', function (): void {
    $user = User::factory()->create([
        'email'    => 'admin-stale-intended-' . str()->uuid()->toString() . '@example.com',
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('super-admin');

    $response = $this
        ->withSession(['url.intended' => url('/dashboard')])
        ->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

    $response->assertRedirect('/admin');
});

it('keeps non-admin users on the customer dashboard after login', function (): void {
    $user = User::factory()->create([
        'email'    => 'customer-login-redirect-' . str()->uuid()->toString() . '@example.com',
        'password' => Hash::make('password'),
    ]);

    $response = $this->post('/login', [
        'email'    => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/dashboard');
});
