<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;


it('allows mobile login for legacy users whose pin still lives in password', function (): void {
    $user = User::factory()->create([
        'dial_code' => '+268',
        'mobile' => '76111111',
        'password' => Hash::make('123456'),
        'transaction_pin' => null,
        'mobile_verified_at' => now(),
        'has_completed_onboarding' => true,
    ]);

    $this->postJson('/api/auth/mobile/login', [
        'dial_code' => '+268',
        'mobile' => '76111111',
        'pin' => '123456',
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
});
