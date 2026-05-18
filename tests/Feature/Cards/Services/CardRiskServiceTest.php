<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Models\Cardholder;
use App\Domain\CardIssuance\ValueObjects\AuthorizationRequest;
use App\Domain\CardSubscriptions\Enums\CardErrorCode;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\CardTransaction;
use App\Domain\CardSubscriptions\Services\CardRiskService;
use App\Models\User;
use DateTimeImmutable;
use DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class CardRiskServiceTest extends TestCase
{
    private CardRiskService $service;

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CardRiskService::class);
    }

    #[Test]
    public function evaluate_authorization_denies_atm_when_plan_disallows_atm(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create();
        $plan = CardPlan::factory()->create([
            'code'        => 'VIRTUAL_LITE_TEST',
            'atm_enabled' => false,
            'active'      => true,
        ]);

        $sub = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active,
        ]);

        $card = Card::factory()->create([
            'user_id'              => $user->id,
            'cardholder_id'        => Cardholder::factory(),
            'card_subscription_id' => $sub->id,
            'status'               => 'active',
        ]);

        $req = new AuthorizationRequest(
            authorizationId: 'auth_1',
            cardToken: $card->issuer_card_token,
            amountCents: 1000,
            currency: 'SZL',
            merchantName: 'Test ATM',
            merchantCategory: '6011',
            timestamp: new DateTimeImmutable(),
        );

        $decision = $this->service->evaluateAuthorization($card, $req);

        self::assertFalse($decision->allowed);
        self::assertSame(CardErrorCode::ATM_NOT_ALLOWED, $decision->code);
    }

    #[Test]
    public function evaluate_authorization_denies_when_decline_velocity_exceeded_in_10_minutes(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create();
        $plan = CardPlan::factory()->create(['atm_enabled' => true, 'active' => true]);

        $sub = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active,
        ]);

        $card = Card::factory()->create([
            'user_id'              => $user->id,
            'cardholder_id'        => Cardholder::factory(),
            'card_subscription_id' => $sub->id,
            'status'               => 'active',
        ]);

        for ($i = 0; $i < 6; $i++) {
            CardTransaction::create([
                'card_id'           => $card->id,
                'user_id'           => $user->id,
                'external_id'       => 'ext_vel_' . $i . '_' . uniqid('', true),
                'merchant_name'     => 'M' . $i,
                'merchant_category' => '5411',
                'amount_cents'      => 100,
                'currency'          => 'SZL',
                'status'            => 'declined',
            ]);
        }

        $req = new AuthorizationRequest(
            authorizationId: 'auth_vel',
            cardToken: $card->issuer_card_token,
            amountCents: 500,
            currency: 'SZL',
            merchantName: 'Shop',
            merchantCategory: '5411',
            timestamp: new DateTimeImmutable(),
        );

        $decision = $this->service->evaluateAuthorization($card, $req);

        self::assertFalse($decision->allowed);
        self::assertSame(CardErrorCode::HIGH_RISK_TRANSACTION, $decision->code);
    }

    #[Test]
    public function evaluate_card_creation_denies_when_user_risk_rating_is_high(): void
    {
        $user = User::factory()->create(['risk_rating' => 'high']);

        $decision = $this->service->evaluateCardCreation($user);

        self::assertFalse($decision->allowed);
        self::assertSame(CardErrorCode::HIGH_RISK_USER, $decision->code);
    }

    private function requireDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('Database not available.');
        }

        foreach (['card_plans', 'card_subscriptions', 'cards', 'card_transactions'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->markTestSkipped("Table `{$table}` does not exist — run migrations first.");
            }
        }
    }
}
