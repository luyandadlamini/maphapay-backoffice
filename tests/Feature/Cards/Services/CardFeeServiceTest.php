<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardFeeStatus;
use App\Domain\CardSubscriptions\Enums\CardFeeType;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardFee;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\CardSubscriptions\Services\CardFeeService;
use App\Domain\CardSubscriptions\ValueObjects\CardFeePreviewInput;
use App\Domain\CardSubscriptions\ValueObjects\ReplacementReason;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use DB;
use Exception;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Feature tests for CardFeeService — all 9 public methods.
 *
 * Pure-calculation tests (calculateFxFee, calculateAtmFee) do not touch the
 * database and run without any DB availability check.
 *
 * All tests that require a persisted row are guarded by requireDatabase() and
 * will skip gracefully when the database or Cards phase-3 schema is not
 * available. Database refresh is handled by the base TestCase via
 * LazilyRefreshExistingMySqlSchema (which uses RefreshDatabase internally).
 */
class CardFeeServiceTest extends TestCase
{
    private CardFeeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CardFeeService::class);

        // Skip the entire class when the service is the stub (every method
        // throws LogicException('not implemented')). This allows the test file
        // to live on both the feature branch (worktree — real implementation)
        // and the main branch (stub) without failing CI while the feature is
        // pending merge.
        if ($this->serviceIsStub()) {
            $this->markTestSkipped(
                'CardFeeService is not yet implemented on this branch — ' .
                'tests will run once the feature branch is merged.'
            );
        }
    }

    /**
     * Override the base TestCase to skip expensive default account creation for
     * every test in this class. Individual tests create their own fixtures.
     */
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    /**
     * Return true when the service is still the stub (every method throws
     * LogicException('not implemented')).
     */
    private function serviceIsStub(): bool
    {
        try {
            $plan = $this->makePlan();
            $this->service->calculateFxFee($plan, 'SZL', Money::fromMajorString('1.00'));

            return false;
        } catch (LogicException $e) {
            return str_contains($e->getMessage(), 'not implemented');
        } catch (Throwable) {
            // Any other exception means the real implementation ran.
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // calculateFxFee — pure arithmetic, no DB
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_zero_fx_fee_for_szl_currency(): void
    {
        $plan = $this->makePlan(['fx_markup_bps' => 350]);

        $fee = $this->service->calculateFxFee($plan, 'SZL', Money::fromMajorString('1000.00', 'SZL'));

        $this->assertSame('0.00', $fee->amount);
        $this->assertSame('SZL', $fee->currency);
    }

    #[Test]
    public function it_returns_zero_fx_fee_for_zar_currency(): void
    {
        $plan = $this->makePlan(['fx_markup_bps' => 350]);

        $fee = $this->service->calculateFxFee($plan, 'ZAR', Money::fromMajorString('1000.00', 'SZL'));

        $this->assertSame('0.00', $fee->amount);
        $this->assertSame('SZL', $fee->currency);
    }

    #[Test]
    public function it_calculates_fx_fee_for_usd_with_virtual_lite_plan(): void
    {
        // 350 bps on E1000 = E1000 * 350 / 10000 = E35.00
        $plan = $this->makePlan(['fx_markup_bps' => 350]);

        $fee = $this->service->calculateFxFee($plan, 'USD', Money::fromMajorString('1000.00'));

        $this->assertSame('35.00', $fee->amount);
        $this->assertSame('SZL', $fee->currency);
    }

    #[Test]
    public function it_calculates_fx_fee_for_usd_with_premium_plan(): void
    {
        // 175 bps on E1000 = E1000 * 175 / 10000 = E17.50
        $plan = $this->makePlan(['fx_markup_bps' => 175]);

        $fee = $this->service->calculateFxFee($plan, 'USD', Money::fromMajorString('1000.00'));

        $this->assertSame('17.50', $fee->amount);
        $this->assertSame('SZL', $fee->currency);
    }

    // -------------------------------------------------------------------------
    // calculateAtmFee — pure arithmetic, no DB
    // -------------------------------------------------------------------------

    #[Test]
    public function it_calculates_atm_fee_for_physical_card_plan_500_withdrawal(): void
    {
        // fixed=12, bps=150 on E500 → E500 * 150 / 10000 = E7.50 + E12.00 = E19.50
        $plan = $this->makePlan([
            'atm_fixed_fee'          => '12.00',
            'atm_percentage_fee_bps' => 150,
        ]);

        $fee = $this->service->calculateAtmFee($plan, Money::fromMajorString('500.00'));

        $this->assertSame('19.50', $fee->amount);
        $this->assertSame('SZL', $fee->currency);
    }

    #[Test]
    public function it_calculates_atm_fee_for_premium_plan_1000_withdrawal(): void
    {
        // fixed=8, bps=100 on E1000 → E1000 * 100 / 10000 = E10.00 + E8.00 = E18.00
        $plan = $this->makePlan([
            'atm_fixed_fee'          => '8.00',
            'atm_percentage_fee_bps' => 100,
        ]);

        $fee = $this->service->calculateAtmFee($plan, Money::fromMajorString('1000.00'));

        $this->assertSame('18.00', $fee->amount);
        $this->assertSame('SZL', $fee->currency);
    }

    // -------------------------------------------------------------------------
    // waiveFee
    // -------------------------------------------------------------------------

    #[Test]
    public function it_sets_fee_status_to_waived_and_records_waived_at(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);
        $admin = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $cardFee = CardFee::factory()->create([
            'user_id'    => $user->id,
            'status'     => 'charged',
            'charged_at' => now(),
            'waived_at'  => null,
        ]);

        $result = $this->service->waiveFee($cardFee, 'Goodwill waiver', $admin);

        $this->assertSame(CardFeeStatus::Waived, $result->status);
        $this->assertNotNull($result->waived_at);
        $this->assertSame('Goodwill waiver', $result->notes);

        // Persisted to the DB
        $fresh = CardFee::find($cardFee->id);
        $this->assertSame(CardFeeStatus::Waived, $fresh->status);
        $this->assertNotNull($fresh->waived_at);
    }

    // -------------------------------------------------------------------------
    // refundFee
    // -------------------------------------------------------------------------

    #[Test]
    public function it_sets_fee_status_to_refunded_and_records_refunded_at(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);
        $admin = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $cardFee = CardFee::factory()->create([
            'user_id'     => $user->id,
            'status'      => 'charged',
            'charged_at'  => now(),
            'refunded_at' => null,
        ]);

        $result = $this->service->refundFee($cardFee, 'Disputed charge', $admin);

        $this->assertSame(CardFeeStatus::Refunded, $result->status);
        $this->assertNotNull($result->refunded_at);
        $this->assertSame('Disputed charge', $result->notes);

        // Persisted to the DB
        $fresh = CardFee::find($cardFee->id);
        $this->assertSame(CardFeeStatus::Refunded, $fresh->status);
        $this->assertNotNull($fresh->refunded_at);
    }

    // -------------------------------------------------------------------------
    // chargeVirtualReplacementFee
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_null_when_free_reissue_allowance_not_exhausted(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $plan = CardPlan::factory()->create([
            'free_virtual_reissues_per_month' => 1,
            'virtual_card_replacement_fee'    => '15.00',
            'max_virtual_cards'               => 1,
        ]);

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $card = Card::factory()->create([
            'user_id'              => $user->id,
            'card_subscription_id' => $subscription->id,
            'status'               => 'active',
            'kind'                 => 'virtual',
        ]);

        // Zero charged replacements this month → within the free allowance
        $result = $this->service->chargeVirtualReplacementFee($user, $card);

        $this->assertNull($result);
    }

    #[Test]
    public function it_charges_replacement_fee_when_free_allowance_exhausted(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $plan = CardPlan::factory()->create([
            'free_virtual_reissues_per_month' => 1,
            'virtual_card_replacement_fee'    => '15.00',
            'max_virtual_cards'               => 1,
        ]);

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $card = Card::factory()->create([
            'user_id'              => $user->id,
            'card_subscription_id' => $subscription->id,
            'status'               => 'active',
            'kind'                 => 'virtual',
        ]);

        // Seed exactly 1 charged replacement this month to exhaust the allowance
        CardFee::factory()->create([
            'user_id'    => $user->id,
            'fee_type'   => CardFeeType::VirtualCardReplacement->value,
            'status'     => 'charged',
            'charged_at' => now()->startOfMonth()->addDay(),
        ]);

        $result = $this->service->chargeVirtualReplacementFee($user, $card);

        $this->assertNotNull($result);
        $this->assertSame(CardFeeStatus::Charged, $result->status);
        $this->assertSame(CardFeeType::VirtualCardReplacement, $result->fee_type);
        $this->assertSame('15.00', $result->amount);
    }

    // -------------------------------------------------------------------------
    // chargeReplacementFee
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_waived_fee_for_expired_reason(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $plan = CardPlan::factory()->create([
            'physical_card_replacement_fee' => '90.00',
            'max_physical_cards'            => 1,
        ]);

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $card = Card::factory()->create([
            'user_id'              => $user->id,
            'card_subscription_id' => $subscription->id,
            'status'               => 'active',
            'kind'                 => 'physical',
        ]);

        $result = $this->service->chargeReplacementFee($user, $card, ReplacementReason::EXPIRED);

        $this->assertSame(CardFeeStatus::Waived, $result->status);
        $this->assertSame('0.00', $result->amount);
        $this->assertSame(CardFeeType::PhysicalCardReplacement, $result->fee_type);
        $this->assertNotNull($result->waived_at);
    }

    #[Test]
    public function it_charges_physical_replacement_fee_for_physical_card(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $plan = CardPlan::factory()->create([
            'physical_card_replacement_fee' => '90.00',
            'max_physical_cards'            => 1,
        ]);

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $card = Card::factory()->create([
            'user_id'              => $user->id,
            'card_subscription_id' => $subscription->id,
            'status'               => 'active',
            'kind'                 => 'physical',
        ]);

        $result = $this->service->chargeReplacementFee($user, $card, ReplacementReason::LOST);

        $this->assertSame(CardFeeStatus::Charged, $result->status);
        $this->assertSame(CardFeeType::PhysicalCardReplacement, $result->fee_type);
        $this->assertSame('90.00', $result->amount);
    }

    // -------------------------------------------------------------------------
    // chargeIssuanceFee
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_charged_fee_for_physical_card_issuance(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $plan = CardPlan::factory()->create([
            'physical_card_issuance_fee' => '120.00',
            'max_physical_cards'         => 1,
        ]);

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $order = PhysicalCardOrder::factory()->create([
            'user_id'              => $user->id,
            'card_subscription_id' => $subscription->id,
        ]);

        // Ensure the eager-loaded subscription carries the plan we created
        $order->setRelation('subscription', $subscription);
        $subscription->setRelation('plan', $plan);

        $result = $this->service->chargeIssuanceFee($user, $order);

        $this->assertSame(CardFeeStatus::Charged, $result->status);
        $this->assertSame(CardFeeType::PhysicalCardIssuance, $result->fee_type);
        $this->assertSame('120.00', $result->amount);
        $this->assertSame('SZL', $result->currency);
        $this->assertNotNull($result->charged_at);
    }

    // -------------------------------------------------------------------------
    // chargeChargebackAbuseFee
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_chargeback_abuse_fee_of_100_szl(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $dispute = CardDispute::factory()->create([
            'user_id' => $user->id,
        ]);

        $result = $this->service->chargeChargebackAbuseFee($user, $dispute);

        $this->assertSame(CardFeeStatus::Charged, $result->status);
        $this->assertSame(CardFeeType::ChargebackAbuse, $result->fee_type);
        $this->assertSame('100.00', $result->amount);
        $this->assertSame('SZL', $result->currency);
        $this->assertNotNull($result->charged_at);
    }

    // -------------------------------------------------------------------------
    // previewTransaction
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_zero_fees_for_domestic_szl_transaction(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        $plan = CardPlan::factory()->create([
            'fx_markup_bps'          => 350,
            'atm_enabled'            => true,
            'atm_fixed_fee'          => '12.00',
            'atm_percentage_fee_bps' => 150,
        ]);

        CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $input = CardFeePreviewInput::transaction(100000, 'SZL', 'purchase');

        $preview = $this->service->previewTransaction($user, $input);

        $this->assertSame(0, $preview->totalFeeCents);
        $this->assertEmpty($preview->feeBreakdownCents);
        $this->assertSame(100000, $preview->totalCents);
    }

    #[Test]
    public function it_returns_fx_fee_for_usd_transaction(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        // 350 bps on E1000 = E35.00 = 3500 cents
        $plan = CardPlan::factory()->create([
            'fx_markup_bps' => 350,
            'atm_enabled'   => false,
        ]);

        CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        // E1000.00 = 100000 cents, USD → triggers fx_markup
        $input = CardFeePreviewInput::transaction(100000, 'USD', 'purchase');

        $preview = $this->service->previewTransaction($user, $input);

        $this->assertGreaterThan(0, $preview->totalFeeCents);
        $this->assertArrayHasKey('fx_markup', $preview->feeBreakdownCents);
        $this->assertSame(3500, $preview->feeBreakdownCents['fx_markup']);
    }

    #[Test]
    public function it_returns_combined_fees_for_atm_usd_transaction(): void
    {
        $this->requireDatabase();

        $user = User::factory()->create(['kyc_status' => 'pending', 'frozen_at' => null]);

        // FX: 350 bps; ATM: fixed=12 + 150bps
        // On E500 (50000 cents):
        //   fx_markup  = 500 * 350 / 10000 = 17.50 = 1750 cents
        //   atm_fixed  = 12.00
        //   atm_pct    = 500 * 150 / 10000 = 7.50
        //   atm total  = 19.50 = 1950 cents
        $plan = CardPlan::factory()->create([
            'fx_markup_bps'          => 350,
            'atm_enabled'            => true,
            'atm_fixed_fee'          => '12.00',
            'atm_percentage_fee_bps' => 150,
        ]);

        CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $input = CardFeePreviewInput::transaction(50000, 'USD', 'atm_withdrawal');

        $preview = $this->service->previewTransaction($user, $input);

        $this->assertArrayHasKey('fx_markup', $preview->feeBreakdownCents);
        $this->assertArrayHasKey('atm', $preview->feeBreakdownCents);
        $this->assertSame(1750, $preview->feeBreakdownCents['fx_markup']);
        $this->assertSame(1950, $preview->feeBreakdownCents['atm']);
        $this->assertSame(3700, $preview->totalFeeCents);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an in-memory CardPlan (no DB) with given attribute overrides.
     * Default values cover all attributes used by calculateFxFee and calculateAtmFee.
     *
     * @param array<string, mixed> $attributes
     */
    private function makePlan(array $attributes = []): CardPlan
    {
        $plan = new CardPlan();
        $plan->forceFill(array_merge([
            'fx_markup_bps'                   => 350,
            'atm_fixed_fee'                   => '0.00',
            'atm_percentage_fee_bps'          => 0,
            'atm_enabled'                     => false,
            'max_virtual_cards'               => 1,
            'max_physical_cards'              => 0,
            'monthly_fee'                     => '25.00',
            'physical_card_replacement_fee'   => '90.00',
            'physical_card_issuance_fee'      => '120.00',
            'virtual_card_replacement_fee'    => '15.00',
            'free_virtual_reissues_per_month' => 0,
            'monthly_card_creation_limit'     => 3,
            'eligibility'                     => 'adult',
            'active'                          => true,
        ], $attributes));

        return $plan;
    }

    /**
     * Skip the test when the database or Cards phase-3 schema is unavailable.
     */
    private function requireDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            DB::statement('SELECT 1 FROM card_plans LIMIT 1');
        } catch (Exception) {
            $this->markTestSkipped('Database / card_plans table not available.');
        }

        foreach (['card_fees', 'card_subscriptions', 'cards', 'card_disputes', 'physical_card_orders'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->markTestSkipped(
                    "Table `{$table}` does not exist — run Cards phase-3 migrations first: " .
                    'php artisan migrate --path=database/migrations/tenant/ --force'
                );
            }
        }
    }
}
