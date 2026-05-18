<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Jobs;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\AuthorizationRequest;
use App\Domain\CardSubscriptions\Jobs\HandleAuthorisationWebhookJob;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardEntitlementService;
use App\Domain\CardSubscriptions\Services\CardFeeService;
use App\Domain\CardSubscriptions\Services\CardRiskService;
use App\Domain\CardSubscriptions\ValueObjects\EntitlementDecision;
use App\Domain\CardSubscriptions\ValueObjects\RiskDecision;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class HandleAuthorisationWebhookJobTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return true;
    }

    public function test_it_handles_authorisation_successfully(): void
    {
        $plan = CardPlan::create([
            'name'             => 'Standard',
            'code'             => 'STANDARD',
            'is_default'       => true,
            'features'         => [],
            'limits'           => [],
            'subscription_fee' => 0,
        ]);

        $subscription = CardSubscription::create([
            'subscriber_user_id'   => $this->user->id,
            'payer_user_id'        => $this->user->id,
            'card_plan_id'         => $plan->id,
            'status'               => 'active',
            'started_at'           => now(),
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        $card = Card::factory()->create([
            'issuer_card_token'    => 'tok_123',
            'user_id'              => $this->user->id,
            'card_subscription_id' => $subscription->id,
        ]);

        $payload = [
            'event_id'          => 'evt_123',
            'card_token'        => 'tok_123',
            'type'              => 'authorisation',
            'authorization_id'  => 'auth_123',
            'amount'            => 1000,
            'currency'          => 'ZAR',
            'merchant_name'     => 'Test Store',
            'merchant_category' => 'Retail',
        ];

        // Mock Entitlement Service — returns real EntitlementDecision VO
        $entitlementService = Mockery::mock(CardEntitlementService::class);
        $entitlementService->shouldReceive('canAuthorize')
            ->once()
            ->with(Mockery::type(Card::class), Mockery::type(AuthorizationRequest::class))
            ->andReturn(EntitlementDecision::allow());

        // Mock Risk Service — returns real RiskDecision VO
        $riskService = Mockery::mock(CardRiskService::class);
        $riskService->shouldReceive('evaluateAuthorization')
            ->once()
            ->with(Mockery::type(Card::class), Mockery::type(AuthorizationRequest::class))
            ->andReturn(RiskDecision::allow());

        // Mock Fee Service — returns zero-fee Money VO
        $feeService = Mockery::mock(CardFeeService::class);
        $feeService->shouldReceive('calculateFxFee')
            ->once()
            ->andReturn(new Money('0', 'ZAR'));

        Log::shouldReceive('info')
            ->withArgs(function (string $message) {
                return str_contains($message, 'Authorisation successful for card')
                    || str_contains($message, 'Approving authorisation for demo');
            });
        Log::shouldReceive('warning')->never();

        $job = new HandleAuthorisationWebhookJob('demo', $payload);
        $job->handle($entitlementService, $riskService, $feeService);

        // If no exception, the happy path ran successfully
        $this->assertTrue(true);
    }

    public function test_it_declines_when_entitlement_denied(): void
    {
        $plan = CardPlan::create([
            'name'             => 'Standard',
            'code'             => 'STANDARD',
            'is_default'       => true,
            'features'         => [],
            'limits'           => [],
            'subscription_fee' => 0,
        ]);

        $subscription = CardSubscription::create([
            'subscriber_user_id'   => $this->user->id,
            'payer_user_id'        => $this->user->id,
            'card_plan_id'         => $plan->id,
            'status'               => 'active',
            'started_at'           => now(),
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        Card::factory()->create([
            'issuer_card_token'    => 'tok_456',
            'user_id'              => $this->user->id,
            'card_subscription_id' => $subscription->id,
        ]);

        $payload = [
            'event_id'         => 'evt_456',
            'card_token'       => 'tok_456',
            'type'             => 'authorisation',
            'authorization_id' => 'auth_456',
            'amount'           => 99999,
            'currency'         => 'ZAR',
        ];

        $entitlementService = Mockery::mock(CardEntitlementService::class);
        $entitlementService->shouldReceive('canAuthorize')
            ->once()
            ->andReturn(EntitlementDecision::deny(\App\Domain\CardSubscriptions\Enums\CardErrorCode::INSUFFICIENT_FUNDS));

        $riskService = Mockery::mock(CardRiskService::class);
        $riskService->shouldReceive('evaluateAuthorization')->never();

        $feeService = Mockery::mock(CardFeeService::class);
        $feeService->shouldReceive('calculateFxFee')->never();

        Log::shouldReceive('info')->once()
            ->withArgs(fn (string $m) => str_contains($m, 'Declining'));

        $job = new HandleAuthorisationWebhookJob('demo', $payload);
        $job->handle($entitlementService, $riskService, $feeService);

        $this->assertTrue(true);
    }

    public function test_it_warns_when_card_token_missing(): void
    {
        $entitlementService = Mockery::mock(CardEntitlementService::class);
        $riskService = Mockery::mock(CardRiskService::class);
        $feeService = Mockery::mock(CardFeeService::class);

        $entitlementService->shouldReceive('canAuthorize')->never();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $m) => str_contains($m, 'missing card_token'));

        $job = new HandleAuthorisationWebhookJob('demo', ['event_id' => 'evt_789']);
        $job->handle($entitlementService, $riskService, $feeService);

        $this->assertTrue(true);
    }
}
