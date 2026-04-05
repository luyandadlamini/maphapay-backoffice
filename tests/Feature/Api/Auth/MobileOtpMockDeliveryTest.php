<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserOtp;

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

it('can send a new login OTP after a previous OTP was verified (user_otps unique slot)', function (): void {
    config([
        'sms.otp_provider'  => 'mock',
        'otp.debug_enabled' => false,
    ]);

    $this->postJson('/api/auth/mobile/login', [
        'dial_code' => '+268',
        'mobile'    => '76199904',
    ])->assertOk();

    $user = User::where('dial_code', '+268')->where('mobile', '76199904')->first();
    expect($user)->not->toBeNull();

    $otpRow = UserOtp::where('user_id', $user->id)->where('type', UserOtp::TYPE_LOGIN)->first();
    expect($otpRow)->not->toBeNull();
    $otpRow->forceFill(['verified_at' => now()])->save();

    $this->postJson('/api/auth/mobile/login', [
        'dial_code' => '+268',
        'mobile'    => '76199904',
    ])->assertOk()
        ->assertJsonPath('success', true);

    expect(UserOtp::where('user_id', $user->id)->where('type', UserOtp::TYPE_LOGIN)->whereNull('verified_at')->count())->toBe(1);
});

it('skip_otp_send creates user without OTP row when server allows it; verify works with debug code', function (): void {
    config([
        'sms.otp_provider'                => 'mock',
        'otp.allow_skip_send_on_register' => true,
        'otp.debug_enabled'               => true,
        'otp.debug_code'                  => '123456',
    ]);

    $this->postJson('/api/auth/mobile/login', [
        'dial_code'     => '+268',
        'mobile'        => '76199906',
        'skip_otp_send' => true,
    ])->assertOk()
        ->assertJsonPath('data.otp_sent', false);

    $user = User::where('dial_code', '+268')->where('mobile', '76199906')->first();
    expect($user)->not->toBeNull();
    expect(UserOtp::where('user_id', $user->id)->where('type', UserOtp::TYPE_LOGIN)->count())->toBe(0);

    $this->postJson('/api/auth/mobile/verify-otp', [
        'dial_code' => '+268',
        'mobile'    => '76199906',
        'otp'       => '123456',
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
});

it('ignores skip_otp_send when allow_skip_send_on_register is false', function (): void {
    config([
        'sms.otp_provider'                => 'mock',
        'otp.allow_skip_send_on_register' => false,
    ]);

    $this->postJson('/api/auth/mobile/login', [
        'dial_code'     => '+268',
        'mobile'        => '76199907',
        'skip_otp_send' => true,
    ])->assertOk()
        ->assertJsonPath('data.otp_sent', true);

    expect(UserOtp::where('user_id', User::where('mobile', '76199907')->value('id'))->count())->toBe(1);
});
