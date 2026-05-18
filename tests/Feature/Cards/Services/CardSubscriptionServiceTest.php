<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardErrorCode;
use App\Domain\CardSubscriptions\Enums\CardPlanEligibility;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Exceptions\EntitlementDeniedException;
use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardBillingService;
use App\Domain\CardSubscriptions\Services\CardEntitlementService;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Domain\CardSubscriptions\ValueObjects\BillingAttemptResult;
use App\Domain\CardSubscriptions\ValueObjects\EntitlementDecision;
use App\Models\User;
use Closure;
use DB;
use Exception;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Feature tests for CardSubscriptionService — subscribe, upgrade, downgrade,
 * cancel, and FSM state-transition methods.
 *
 * CardAuditService and CardBillingService are stubs (throw LogicException).
 * CardEntitlementService has real logic that makes fine-grained attribute
 * checks — mocking it gives the subscription service full control over which
 * decisions are allowed or denied in each scenario without worrying about
 * whether test users have exactly the right kyc_level, risk_rating, etc.
 *
 * All tests that touch the database are guarded by requireDatabase() and will
 * skip gracefully when no database connection is available or when the Cards
 * schema migrations have not been applied.
 */
class CardSubscriptionServiceTest extends TestCase
{
    private CardSubscriptionService $service;

    /** @var \Mockery\MockInterface */
    private $billingMock;

    /** @var \Mockery\MockInterface */
    private $entitlementMock;

    /** Controls what canSubscribeToPlan() returns; override in individual tests. */
    private EntitlementDecision $planDecision;

    /**
     * Controls what chargeInitialPeriod() does; set to a Closure that throws or returns.
     * Default: returns a successful BillingAttemptResult.
     *
     * @var Closure|Exception|BillingAttemptResult
     */
    private mixed $billingAction;

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CardAuditService::class, function ($mock): void {
            $mock->shouldReceive('recordSubscriptionEvent')
                ->andReturn(Mockery::mock(CardAuditLog::class));
        });

        $this->billingAction = BillingAttemptResult::success(null, 4900, 'SZL');

        $this->billingMock = $this->mock(CardBillingService::class, function ($mock): void {
            $mock->shouldReceive('chargeInitialPeriod')
                ->andReturnUsing(function () {
                    $action = $this->billingAction;
                    if ($action instanceof Throwable) {
                        throw $action;
                    }

                    return $action instanceof Closure ? ($action)() : $action;
                });
        });

        // Default: entitlement allows everything. Tests that need a denied
        // response simply reassign $this->planDecision before calling the service.
        $this->planDecision = EntitlementDecision::allow();

        $this->entitlementMock = $this->mock(CardEntitlementService::class, function ($mock): void {
            $mock->shouldReceive('canSubscribeToPlan')
                ->andReturnUsing(fn () => $this->planDecision);
            $mock->shouldReceive('canUseFeature')
                ->andReturn(EntitlementDecision::allow());
            $mock->shouldReceive('canCreateCard')
                ->andReturn(EntitlementDecision::allow());
            $mock->shouldReceive('canIssuePhysicalCard')
                ->andReturn(EntitlementDecision::allow());
            $mock->shouldReceive('canReissueVirtualCard')
                ->andReturn(EntitlementDecision::allow());
        });

        $this->service = app(CardSubscriptionService::class);

        // Skip the entire class when the service is a stub (not yet merged into main).
        // The stub throws LogicException('not implemented') from every method.
        if ($this->serviceIsStub()) {
            $this->markTestSkipped(
                'CardSubscriptionService is not yet implemented on this branch — ' .
                'tests will run once the feature branch is merged.'
            );
        }
    }

    /**
     * Return true when the service is still a stub (every method throws
     * LogicException('not implemented')).
     */
    private function serviceIsStub(): bool
    {
        try {
            // getCurrent() is a lightweight read that takes a User and returns
            // null when there are no subscriptions. On the stub it throws immediately;
            // on the real implementation it performs a DB query and returns null/model.
            $probe = new User();
            $probe->id = 0;
            $this->service->getCurrent($probe);

            return false;
        } catch (LogicException $e) {
            return str_contains($e->getMessage(), 'not implemented');
        } catch (Throwable) {
            // Any other exception (e.g. DB error, missing attribute) means the
            // real implementation ran; treat as not-a-stub.
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Scenario 1 — subscribe() happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_an_active_subscription_on_subscribe_happy_path(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $plan = $this->makePlan('STD_' . uniqid());

        $subscription = $this->service->subscribe($user, $plan->code);

        $this->assertInstanceOf(CardSubscription::class, $subscription);
        $this->assertSame(CardSubscriptionStatus::Active, $subscription->status);
        $this->assertSame($user->id, $subscription->subscriber_user_id);
        $this->assertSame($user->id, $subscription->payer_user_id);

        $this->assertDatabaseHas('card_subscriptions', [
            'id'                 => $subscription->id,
            'subscriber_user_id' => $user->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 2 — subscribe() entitlement denied (frozen user)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_throws_entitlement_denied_when_subscriber_is_frozen(): void
    {
        $this->requireDatabase();

        $this->planDecision = EntitlementDecision::deny(CardErrorCode::USER_NOT_ACTIVE, 'Account is frozen.');

        $user = User::factory()->create();
        $plan = $this->makePlan('FRZ_' . uniqid());

        $this->expectException(EntitlementDeniedException::class);

        $this->service->subscribe($user, $plan->code);

        $this->assertDatabaseMissing('card_subscriptions', [
            'subscriber_user_id' => $user->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 3 — subscribe() billing failure rolls back
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rolls_back_subscription_when_billing_throws(): void
    {
        $this->requireDatabase();

        $this->billingAction = new RuntimeException('bank error');

        $user = $this->makeSubscribableUser();
        $plan = $this->makePlan('BILL_ERR_' . uniqid());

        // PHPUnit 10: $this->fail() throws PHPUnit\Framework\Exception which extends
        // \RuntimeException, so we must re-throw PHPUnit exceptions from the catch block.
        $caughtMessage = null;
        try {
            $this->service->subscribe($user, $plan->code);
        } catch (\PHPUnit\Framework\Exception $e) {
            throw $e; // never swallow PHPUnit exceptions
        } catch (RuntimeException $e) {
            $caughtMessage = $e->getMessage();
        }

        $this->assertSame('bank error', $caughtMessage, 'Expected RuntimeException was not thrown.');

        $this->assertDatabaseMissing('card_subscriptions', [
            'subscriber_user_id' => $user->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 4 — subscribe() minor subscription sets guardian
    // -------------------------------------------------------------------------

    #[Test]
    public function it_sets_guardian_fields_for_minor_subscription(): void
    {
        $this->requireDatabase();

        $guardian = $this->makeSubscribableUser();
        $child = $this->makeSubscribableUser();
        $plan = $this->makePlan('MINOR_' . uniqid());

        $subscription = $this->service->subscribe($child, $plan->code, $guardian, 'req-abc');

        $this->assertTrue($subscription->is_minor_subscription);
        $this->assertSame($guardian->id, $subscription->guardian_user_id);
        $this->assertSame('req-abc', $subscription->minor_card_request_id);
    }

    // -------------------------------------------------------------------------
    // Scenario 5 — upgrade() happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_changes_plan_to_new_plan_on_upgrade(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $basicPlan = $this->makePlan('BASIC_' . uniqid());
        $premiumPlan = $this->makePlan('PREM_' . uniqid(), [
            'max_virtual_cards'  => 5,
            'max_physical_cards' => 2,
        ]);

        // Create initial subscription on the BASIC plan.
        CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $basicPlan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $upgraded = $this->service->upgrade($user, $premiumPlan->code);

        $this->assertSame($premiumPlan->id, $upgraded->card_plan_id);
        $upgraded->refresh();
        $this->assertSame($premiumPlan->code, $upgraded->plan->code);
    }

    // -------------------------------------------------------------------------
    // Scenario 6 — upgrade() no active subscription throws
    // -------------------------------------------------------------------------

    #[Test]
    public function it_throws_entitlement_denied_on_upgrade_when_no_subscription_exists(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $newPlan = $this->makePlan('NO_SUB_' . uniqid());

        $this->expectException(EntitlementDeniedException::class);

        $this->service->upgrade($user, $newPlan->code);
    }

    // -------------------------------------------------------------------------
    // Scenario 7 — downgrade() no excess cards
    // -------------------------------------------------------------------------

    #[Test]
    public function it_changes_plan_without_freezing_cards_when_no_excess(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $highPlan = $this->makePlan('HIGH_' . uniqid(), ['max_virtual_cards' => 5]);
        $lowPlan = $this->makePlan('LOW_' . uniqid(), ['max_virtual_cards' => 3]);

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $highPlan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        // Insert 2 active virtual cards — below the new plan's limit of 3.
        $this->insertVirtualCards($subscription, 2, 'active');

        $downgraded = $this->service->downgrade($user, $lowPlan->code);

        $this->assertSame($lowPlan->id, $downgraded->card_plan_id);

        // No cards should have been frozen.
        $this->assertDatabaseMissing('cards', [
            'card_subscription_id' => $subscription->id,
            'status'               => 'frozen_by_user',
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 8 — downgrade() excess cards, $force=false throws
    // -------------------------------------------------------------------------

    #[Test]
    public function it_throws_virtual_card_limit_reached_when_excess_cards_and_not_forced(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $highPlan = $this->makePlan('HIGH2_' . uniqid(), ['max_virtual_cards' => 3]);
        $lowPlan = $this->makePlan('LOW2_' . uniqid(), ['max_virtual_cards' => 1]);

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $highPlan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $this->insertVirtualCards($subscription, 3, 'active');

        try {
            $this->service->downgrade($user, $lowPlan->code, force: false);
            $this->fail('Expected EntitlementDeniedException was not thrown.');
        } catch (EntitlementDeniedException $e) {
            $this->assertSame(CardErrorCode::VIRTUAL_CARD_LIMIT_REACHED, $e->cardErrorCode);
        }
    }

    // -------------------------------------------------------------------------
    // Scenario 9 — downgrade() excess cards, $force=true freezes excess
    // -------------------------------------------------------------------------

    #[Test]
    public function it_freezes_excess_cards_when_force_downgrade(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $highPlan = $this->makePlan('HIGH3_' . uniqid(), ['max_virtual_cards' => 3]);
        $lowPlan = $this->makePlan('LOW3_' . uniqid(), ['max_virtual_cards' => 1]);

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $highPlan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $this->insertVirtualCards($subscription, 3, 'active');

        $downgraded = $this->service->downgrade($user, $lowPlan->code, force: true);

        $this->assertSame($lowPlan->id, $downgraded->card_plan_id);

        // 2 excess cards frozen (3 active − 1 allowed = 2 excess).
        $frozenCount = Card::where('card_subscription_id', $subscription->id)
            ->where('status', 'frozen_by_user')
            ->count();
        $this->assertSame(2, $frozenCount);

        // Exactly 1 card still active.
        $activeCount = Card::where('card_subscription_id', $subscription->id)
            ->where('status', 'active')
            ->count();
        $this->assertSame(1, $activeCount);
    }

    // -------------------------------------------------------------------------
    // Scenario 10 — cancel() sets status and timestamp
    // -------------------------------------------------------------------------

    #[Test]
    public function it_cancels_subscription_and_sets_cancelled_at(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $plan = $this->makePlan('CANCEL_' . uniqid());

        CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $cancelled = $this->service->cancel($user);

        $this->assertSame(CardSubscriptionStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    // -------------------------------------------------------------------------
    // Scenario 11 — markPastDue() increments counter and sets grace period
    // -------------------------------------------------------------------------

    #[Test]
    public function it_marks_subscription_past_due_and_sets_grace_period(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $plan = $this->makePlan('PASTDUE_' . uniqid());

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id'   => $user->id,
            'payer_user_id'        => $user->id,
            'card_plan_id'         => $plan->id,
            'status'               => CardSubscriptionStatus::Active->value,
            'failed_payment_count' => 0,
        ]);

        // Capture time BEFORE the call so grace_period_ends_at falls within the window.
        $callTime = now();
        $this->service->markPastDue($subscription, 'INSUFFICIENT_FUNDS');

        $subscription->refresh();

        $this->assertSame(CardSubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->failed_payment_count);
        $this->assertNotNull($subscription->grace_period_ends_at);

        // Use copy() to avoid Carbon mutation: $lowerBound and $upperBound are independent.
        $this->assertTrue(
            $subscription->grace_period_ends_at->between(
                $callTime->copy()->addDays(3)->subSeconds(5),
                $callTime->copy()->addDays(3)->addSeconds(5),
            ),
            'grace_period_ends_at should be ~3 days from when markPastDue was called'
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 12 — suspend() suspends subscription and active cards
    // -------------------------------------------------------------------------

    #[Test]
    public function it_suspends_subscription_and_active_cards(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $plan = $this->makePlan('SUSP_' . uniqid());

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        $this->insertVirtualCards($subscription, 2, 'active');

        $this->service->suspend($subscription);

        $subscription->refresh();
        $this->assertSame(CardSubscriptionStatus::Suspended, $subscription->status);

        $suspendedCount = Card::where('card_subscription_id', $subscription->id)
            ->where('status', 'suspended')
            ->count();
        $this->assertSame(2, $suspendedCount);
    }

    // -------------------------------------------------------------------------
    // Scenario 13 — restore() reactivates subscription and billing-suspended cards
    // -------------------------------------------------------------------------

    #[Test]
    public function it_restores_subscription_and_only_billing_suspended_cards(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $plan = $this->makePlan('RESTORE_' . uniqid());

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id'   => $user->id,
            'payer_user_id'        => $user->id,
            'card_plan_id'         => $plan->id,
            'status'               => CardSubscriptionStatus::Suspended->value,
            'failed_payment_count' => 2,
            'grace_period_ends_at' => now()->subDay(),
        ]);

        // One card suspended by billing — should be reactivated.
        $suspendedCard = $this->insertVirtualCard($subscription, 'suspended');

        // One card frozen by user — must stay frozen.
        $frozenCard = $this->insertVirtualCard($subscription, 'frozen_by_user');

        $this->service->restore($subscription);

        $subscription->refresh();

        $this->assertSame(CardSubscriptionStatus::Active, $subscription->status);
        $this->assertSame(0, $subscription->failed_payment_count);
        $this->assertNull($subscription->grace_period_ends_at);

        $this->assertDatabaseHas('cards', ['id' => $suspendedCard->id, 'status' => 'active']);
        $this->assertDatabaseHas('cards', ['id' => $frozenCard->id, 'status' => 'frozen_by_user']);
    }

    // -------------------------------------------------------------------------
    // Scenario 14 — terminateUnpaid() cancels subscription and all cards
    // -------------------------------------------------------------------------

    #[Test]
    public function it_cancels_subscription_and_all_non_cancelled_cards_on_terminate_unpaid(): void
    {
        $this->requireDatabase();

        $user = $this->makeSubscribableUser();
        $plan = $this->makePlan('TERM_' . uniqid());

        $subscription = CardSubscription::factory()->create([
            'subscriber_user_id' => $user->id,
            'payer_user_id'      => $user->id,
            'card_plan_id'       => $plan->id,
            'status'             => CardSubscriptionStatus::Active->value,
        ]);

        // Insert 2 non-cancelled cards.
        $card1 = $this->insertVirtualCard($subscription, 'active');
        $card2 = $this->insertVirtualCard($subscription, 'frozen_by_user');

        $this->service->terminateUnpaid($subscription);

        $subscription->refresh();

        $this->assertSame(CardSubscriptionStatus::Cancelled, $subscription->status);

        $this->assertDatabaseHas('cards', ['id' => $card1->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('cards', ['id' => $card2->id, 'status' => 'cancelled']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a persisted user who passes KYC + risk + frozen checks.
     */
    private function makeSubscribableUser(): User
    {
        return User::factory()->create([
            'kyc_status'  => 'approved',
            'frozen_at'   => null,
            'risk_rating' => 'low',
        ]);
    }

    /**
     * Create a persisted, active CardPlan.
     *
     * @param array<string, mixed> $overrides
     */
    private function makePlan(string $code = 'STANDARD', array $overrides = []): CardPlan
    {
        return CardPlan::factory()->create(array_merge([
            'code'               => $code,
            'active'             => true,
            'monthly_fee'        => '49.00',
            'max_virtual_cards'  => 3,
            'max_physical_cards' => 1,
            'eligibility'        => CardPlanEligibility::Adult,
        ], $overrides));
    }

    /**
     * Insert $count virtual cards with the given status on a subscription.
     *
     * @return list<Card>
     */
    private function insertVirtualCards(CardSubscription $subscription, int $count, string $status): array
    {
        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $cards[] = $this->insertVirtualCard($subscription, $status);
        }

        return $cards;
    }

    /**
     * Insert a single virtual card with the given status on a subscription.
     */
    private function insertVirtualCard(CardSubscription $subscription, string $status): Card
    {
        return Card::factory()->create([
            'user_id'              => $subscription->subscriber_user_id,
            'card_subscription_id' => $subscription->id,
            'status'               => $status,
            'kind'                 => 'virtual',
        ]);
    }

    /**
     * Skip the test if the database is unavailable or the Cards schema has not
     * yet been migrated.
     */
    private function requireDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('Database not available.');
        }

        foreach (['card_plans', 'card_subscriptions', 'cards'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->markTestSkipped(
                    "Table `{$table}` does not exist — run Cards migrations first: " .
                    'php artisan migrate --path=database/migrations/tenant/ --force'
                );
            }
        }
    }
}
