<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AuthorizedTransaction\Services;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\MoneyMovementVerificationPolicyResolver;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class MoneyMovementVerificationPolicyResolverTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name' => 'Swazi Lilangeni',
                'type' => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        config([
            'maphapay_migration.money_movement.send_money.step_up_threshold' => '100.00',
            'maphapay_migration.money_movement.request_money.step_up_threshold' => '100.00',
            'maphapay_migration.money_movement.risk_signals' => [
                'velocity' => [
                    'lookback_minutes' => 15,
                    'max_initiations' => 3,
                ],
                'verification_failures' => [
                    'lookback_minutes' => 30,
                    'max_failures' => 2,
                ],
            ],
        ]);
    }

    #[Test]
    public function it_defaults_to_none_for_low_risk_send_money_when_transaction_pin_is_not_set(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            clientHint: 'sms',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_NONE, $policy['verification_type']);
        $this->assertSame('none', $policy['next_step']);
        $this->assertSame('user_preference', $policy['reason']);
        $this->assertNull($policy['risk_reason']);
        $this->assertSame('sms', $policy['client_hint']);
    }

    #[Test]
    public function it_defaults_to_pin_for_low_risk_send_money_when_transaction_pin_is_set(): void
    {
        $user = User::factory()->create(['transaction_pin' => '1234']);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_PIN, $policy['verification_type']);
        $this->assertSame('pin', $policy['next_step']);
        $this->assertSame('user_preference', $policy['reason']);
        $this->assertNull($policy['risk_reason']);
    }

    #[Test]
    public function it_steps_up_to_otp_when_threshold_is_exceeded_and_the_user_has_no_pin(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '100.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
        $this->assertSame('amount_threshold', $policy['reason']);
        $this->assertSame('amount_threshold_exceeded', $policy['risk_reason']);
    }

    #[Test]
    public function it_defaults_request_money_to_pin_when_the_user_has_a_transaction_pin(): void
    {
        $user = User::factory()->create(['transaction_pin' => '1234']);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveRequestMoneyPolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            operationType: AuthorizedTransaction::REMARK_REQUEST_MONEY,
            clientHint: 'sms',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_PIN, $policy['verification_type']);
        $this->assertSame('pin', $policy['next_step']);
        $this->assertSame('user_preference', $policy['reason']);
        $this->assertNull($policy['risk_reason']);
        $this->assertSame('sms', $policy['client_hint']);
    }

    #[Test]
    public function it_defaults_request_money_to_otp_when_the_user_has_no_transaction_pin(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveRequestMoneyPolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            operationType: AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            clientHint: 'pin',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
        $this->assertSame('user_preference', $policy['reason']);
        $this->assertNull($policy['risk_reason']);
        $this->assertSame('pin', $policy['client_hint']);
    }

    #[Test]
    public function it_steps_up_low_risk_send_money_when_recent_money_movement_velocity_is_high(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        AuthorizedTransaction::query()->create([
            'user_id' => $user->id,
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx' => 'TRX-VEL-1',
            'payload' => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        AuthorizedTransaction::query()->create([
            'user_id' => $user->id,
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'trx' => 'TRX-VEL-2',
            'payload' => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        AuthorizedTransaction::query()->create([
            'user_id' => $user->id,
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'trx' => 'TRX-VEL-3',
            'payload' => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status' => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_PIN,
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
        $this->assertSame('risk_signal', $policy['reason']);
        $this->assertSame('velocity_limit_exceeded', $policy['risk_reason']);
    }

    #[Test]
    public function it_steps_up_low_risk_send_money_after_recent_verification_failures(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        AuthorizedTransaction::query()->create([
            'user_id' => $user->id,
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx' => 'TRX-FAIL-1',
            'payload' => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status' => AuthorizedTransaction::STATUS_FAILED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_PIN,
            'verification_failures' => 1,
            'failure_reason' => 'Invalid transaction PIN.',
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(8),
        ]);

        AuthorizedTransaction::query()->create([
            'user_id' => $user->id,
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'trx' => 'TRX-FAIL-2',
            'payload' => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status' => AuthorizedTransaction::STATUS_FAILED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
            'verification_failures' => 1,
            'failure_reason' => 'Invalid OTP.',
            'expires_at' => now()->addHour(),
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(6),
        ]);

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
        $this->assertSame('risk_signal', $policy['reason']);
        $this->assertSame('recent_verification_failures', $policy['risk_reason']);
    }
}
