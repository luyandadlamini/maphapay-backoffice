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
}
