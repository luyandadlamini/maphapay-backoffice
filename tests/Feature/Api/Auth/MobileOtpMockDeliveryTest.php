<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores mock-provider OTP without SMS and accepts spaced OTP when debug bypass is on', function (): void {
    config(['sms.otp_provider' => 'mock']);

    $this->postJson('/api/auth/mobile/login', [
        'dial_code' => '+268',
        'mobile'    => '76199901',
    ])->assertOk()
        ->assertJsonPath('success', true);

    $user = User::where('dial_code', '+268')->where('mobile', '76199901')->first();
    expect($user)->not->toBeNull();
    expect(UserOtp::where('user_id', $user->id)->where('type', UserOtp::TYPE_LOGIN)->count())->toBe(1);

    config([
        'otp.debug_enabled' => true,
        'otp.debug_code'    => '123456',
    ]);

    $this->postJson('/api/auth/mobile/login', [
        'dial_code' => '+268',
        'mobile'    => '76199902',
    ])->assertOk();

    $this->postJson('/api/auth/mobile/verify-otp', [
        'dial_code' => '+268',
        'mobile'    => '76199902',
        'otp'       => '12 34 56',
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
});
