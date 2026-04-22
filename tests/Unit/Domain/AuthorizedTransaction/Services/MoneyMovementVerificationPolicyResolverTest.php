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
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        config([
            'maphapay_migration.money_movement.send_money.step_up_threshold'    => '100.00',
            'maphapay_migration.money_movement.request_money.step_up_threshold' => '100.00',
            'maphapay_migration.money_movement.risk_signals'                    => [
                'velocity' => [
                    'lookback_minutes' => 15,
                    'max_initiations'  => 3,
                ],
                'verification_failures' => [
                    'lookback_minutes' => 30,
                    'max_failures'     => 2,
                ],
                'amount_anomaly' => [
                    'lookback_minutes' => 1440,
                    'min_samples'      => 3,
                    'multiplier'       => 4,
                ],
                'recipient_churn' => [
                    'lookback_minutes'            => 1440,
                    'max_distinct_counterparties' => 3,
                ],
            ],
        ]);

        \App\Models\Setting::set('send_money_threshold_low_enhanced_or_full', 5000, [
            'type'        => 'float',
            'label'       => 'Low Risk Threshold',
            'description' => 'Test threshold',
            'group'       => 'limits',
        ]);
        \App\Models\Setting::set('send_money_threshold_medium_or_standard', 2500, [
            'type'        => 'float',
            'label'       => 'Medium Risk Threshold',
            'description' => 'Test threshold',
            'group'       => 'limits',
        ]);
        \App\Models\Setting::set('send_money_threshold_high_or_basic', 1000, [
            'type'        => 'float',
            'label'       => 'High Risk Threshold',
            'description' => 'Test threshold',
            'group'       => 'limits',
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
        $user = User::factory()->create(['transaction_pin' => '1234', 'transaction_pin_enabled' => true]);
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
    public function it_defaults_to_none_for_low_risk_send_money_when_transaction_pin_exists_but_is_disabled(): void
    {
        $user = User::factory()->create(['transaction_pin' => '1234', 'transaction_pin_enabled' => false]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            clientHint: 'pin',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_NONE, $policy['verification_type']);
        $this->assertSame('none', $policy['next_step']);
        $this->assertSame('user_preference', $policy['reason']);
    }

    #[Test]
    public function it_steps_up_to_otp_when_threshold_is_exceeded_and_the_user_has_no_pin(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '1000.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
        $this->assertSame('amount_threshold', $policy['reason']);
        $this->assertSame('amount_threshold_exceeded', $policy['risk_reason']);
    }

    #[Test]
    public function it_steps_up_to_pin_when_threshold_is_exceeded_and_the_user_has_a_disabled_transaction_pin(): void
    {
        $user = User::factory()->create(['transaction_pin' => '1234', 'transaction_pin_enabled' => false]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '1000.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_PIN, $policy['verification_type']);
        $this->assertSame('pin', $policy['next_step']);
        $this->assertSame('amount_threshold', $policy['reason']);
        $this->assertSame('amount_threshold_exceeded', $policy['risk_reason']);
    }

    #[Test]
    public function it_uses_the_low_risk_enhanced_threshold_for_send_money(): void
    {
        $user = User::factory()->create([
            'transaction_pin' => null,
            'kyc_level'       => 'enhanced',
            'risk_rating'     => 'low',
        ]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '200.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(500000, $policy['step_up_threshold_minor']);
        $this->assertSame(AuthorizedTransaction::VERIFICATION_NONE, $policy['verification_type']);
        $this->assertSame('none', $policy['next_step']);
    }

    #[Test]
    public function it_uses_the_stricter_medium_threshold_when_kyc_or_risk_requires_it(): void
    {
        $user = User::factory()->create([
            'transaction_pin' => null,
            'kyc_level'       => 'enhanced',
            'risk_rating'     => 'medium',
        ]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '2500.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(250000, $policy['step_up_threshold_minor']);
        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
        $this->assertSame('amount_threshold', $policy['reason']);
    }

    #[Test]
    public function it_uses_the_stricter_high_threshold_when_the_profile_is_basic_or_high_risk(): void
    {
        $user = User::factory()->create([
            'transaction_pin' => null,
            'kyc_level'       => 'full',
            'risk_rating'     => 'high',
        ]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '1000.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(100000, $policy['step_up_threshold_minor']);
        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
    }

    #[Test]
    public function it_prefers_the_per_user_send_money_threshold_override(): void
    {
        $user = User::factory()->create([
            'transaction_pin'                       => null,
            'kyc_level'                             => 'enhanced',
            'risk_rating'                           => 'low',
            'send_money_step_up_threshold_override' => '750.00',
        ]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '800.00',
            asset: $asset,
            clientHint: 'none',
        );

        $this->assertSame(75000, $policy['step_up_threshold_minor']);
        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
    }

    #[Test]
    public function it_defaults_request_money_create_to_none_even_when_the_user_has_a_transaction_pin(): void
    {
        $user = User::factory()->create(['transaction_pin' => '1234']);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveRequestMoneyCreatePolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            clientHint: 'sms',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_NONE, $policy['verification_type']);
        $this->assertSame('none', $policy['next_step']);
        $this->assertSame('request_creation_no_funds_moved', $policy['reason']);
        $this->assertNull($policy['risk_reason']);
        $this->assertSame('sms', $policy['client_hint']);
    }

    #[Test]
    public function it_defaults_request_money_create_to_none_when_the_user_has_no_transaction_pin(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveRequestMoneyCreatePolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            clientHint: 'pin',
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_NONE, $policy['verification_type']);
        $this->assertSame('none', $policy['next_step']);
        $this->assertSame('request_creation_no_funds_moved', $policy['reason']);
        $this->assertNull($policy['risk_reason']);
        $this->assertSame('pin', $policy['client_hint']);
    }

    #[Test]
    public function it_steps_up_low_risk_send_money_when_recent_money_movement_velocity_is_high(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        AuthorizedTransaction::query()->create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'               => 'TRX-VEL-1',
            'payload'           => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
            'expires_at'        => now()->addHour(),
            'created_at'        => now()->subMinutes(5),
            'updated_at'        => now()->subMinutes(5),
        ]);

        AuthorizedTransaction::query()->create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'trx'               => 'TRX-VEL-2',
            'payload'           => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
            'expires_at'        => now()->addHour(),
            'created_at'        => now()->subMinutes(4),
            'updated_at'        => now()->subMinutes(4),
        ]);

        AuthorizedTransaction::query()->create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'trx'               => 'TRX-VEL-3',
            'payload'           => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_PIN,
            'expires_at'        => now()->addHour(),
            'created_at'        => now()->subMinutes(3),
            'updated_at'        => now()->subMinutes(3),
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
            'user_id'               => $user->id,
            'remark'                => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'                   => 'TRX-FAIL-1',
            'payload'               => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status'                => AuthorizedTransaction::STATUS_FAILED,
            'verification_type'     => AuthorizedTransaction::VERIFICATION_PIN,
            'verification_failures' => 1,
            'failure_reason'        => 'Invalid transaction PIN.',
            'expires_at'            => now()->addHour(),
            'created_at'            => now()->subMinutes(8),
            'updated_at'            => now()->subMinutes(8),
        ]);

        AuthorizedTransaction::query()->create([
            'user_id'               => $user->id,
            'remark'                => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'trx'                   => 'TRX-FAIL-2',
            'payload'               => ['amount' => '10.00', 'asset_code' => 'SZL'],
            'status'                => AuthorizedTransaction::STATUS_FAILED,
            'verification_type'     => AuthorizedTransaction::VERIFICATION_OTP,
            'verification_failures' => 1,
            'failure_reason'        => 'Invalid OTP.',
            'expires_at'            => now()->addHour(),
            'created_at'            => now()->subMinutes(6),
            'updated_at'            => now()->subMinutes(6),
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

    #[Test]
    public function it_steps_up_when_the_amount_is_anomalously_higher_than_recent_completed_transfers(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        foreach ([10.00, 12.00, 11.50] as $index => $historicalAmount) {
            AuthorizedTransaction::query()->create([
                'user_id' => $user->id,
                'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
                'trx'     => sprintf('TRX-AMT-%02d', $index),
                'payload' => [
                    'amount'          => number_format($historicalAmount, 2, '.', ''),
                    'asset_code'      => 'SZL',
                    'to_account_uuid' => 'recipient-' . $index,
                ],
                'status'            => AuthorizedTransaction::STATUS_COMPLETED,
                'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
                'expires_at'        => now()->addHour(),
                'created_at'        => now()->subMinutes(10 - $index),
                'updated_at'        => now()->subMinutes(10 - $index),
            ]);
        }

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '60.00',
            asset: $asset,
            clientHint: 'none',
            context: [
                'recipient_account_uuid' => 'recipient-high-value',
            ],
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
        $this->assertSame('risk_signal', $policy['reason']);
        $this->assertSame('amount_anomaly_detected', $policy['risk_reason']);
    }

    #[Test]
    public function it_steps_up_when_the_user_is_sending_to_a_new_counterparty_after_high_recent_recipient_churn(): void
    {
        $user = User::factory()->create(['transaction_pin' => null]);
        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        foreach (['recipient-a', 'recipient-b', 'recipient-c'] as $index => $counterparty) {
            AuthorizedTransaction::query()->create([
                'user_id' => $user->id,
                'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
                'trx'     => sprintf('TRX-CHN-%02d', $index),
                'payload' => [
                    'amount'          => '10.00',
                    'asset_code'      => 'SZL',
                    'to_account_uuid' => $counterparty,
                ],
                'status'            => AuthorizedTransaction::STATUS_COMPLETED,
                'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
                'expires_at'        => now()->addHour(),
                'created_at'        => now()->subMinutes(20 - $index),
                'updated_at'        => now()->subMinutes(20 - $index),
            ]);
        }

        $policy = app(MoneyMovementVerificationPolicyResolver::class)->resolveSendMoneyPolicy(
            user: $user,
            amount: '10.00',
            asset: $asset,
            clientHint: 'none',
            context: [
                'recipient_account_uuid' => 'recipient-d',
            ],
        );

        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $policy['verification_type']);
        $this->assertSame('otp', $policy['next_step']);
        $this->assertSame('risk_signal', $policy['reason']);
        $this->assertSame('recipient_churn_detected', $policy['risk_reason']);
    }
}
