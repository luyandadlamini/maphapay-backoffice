<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionBillingResult;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardBillingService;
use App\Domain\CardSubscriptions\ValueObjects\BillingAttemptResult;
use App\Domain\Wallet\Services\WalletService;
use App\Models\User;
use DB;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Feature tests for CardBillingService — chargeInitialPeriod, billRenewal,
 * retryFailedPayment, handleSuccessfulPayment, and handleFailedPayment.
 *
 * WalletService is mocked throughout to prevent async workflow dispatch.
 * All balance checking uses real DB rows (Account + AccountBalance) inserted
 * directly so hasSufficientBalance() operates against real data.
 *
 * All tests that touch the database are guarded by requireDatabase() and will
 * skip gracefully when no database connection is available or when the Cards
 * schema migrations have not been applied.
 */
class CardBillingServiceTest extends TestCase
{
    private CardBillingService $service;

    /** @var \Mockery\MockInterface */
    private $walletMock;

    /** Counts the number of times WalletService::withdraw() is called. */
    public int $withdrawCallCount = 0;

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mock WalletService to prevent async workflow dispatch; track call count
        $this->withdrawCallCount = 0;
        $self = $this;

        $this->walletMock = $this->mock(WalletService::class, function ($mock) use ($self): void {
            $mock->shouldReceive('withdraw')
                ->andReturnUsing(function () use ($self): void {
                    $self->withdrawCallCount++;
                });
        });

        $this->service = app(CardBillingService::class);
    }

    // -------------------------------------------------------------------------
    // Scenario 1 — chargeInitialPeriod: insufficient funds
    // -------------------------------------------------------------------------

    #[Test]
    public function chargeInitialPeriod_returns_failed_with_insufficient_funds_when_balance_is_zero(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();
        $this->createAccountWithBalance($user, 0);

        $subscription = $this->makeSubscription($user);

        $result = $this->service->chargeInitialPeriod($subscription);

        $this->assertSame('failed', $result->result);
        $this->assertSame('INSUFFICIENT_FUNDS', $result->reason);

        $this->assertDatabaseHas('card_subscription_billing_attempts', [
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Failed->value,
            'failure_reason'       => 'INSUFFICIENT_FUNDS',
        ]);

        $this->assertDatabaseMissing('card_fees', [
            'related_entity_id' => $subscription->id,
        ]);

        $subscription->refresh();
        $this->assertSame(CardSubscriptionStatus::PastDue, $subscription->status);
    }

    // -------------------------------------------------------------------------
    // Scenario 2 — chargeInitialPeriod: account frozen
    // -------------------------------------------------------------------------

    #[Test]
    public function chargeInitialPeriod_returns_failed_with_account_frozen_when_account_is_frozen(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();
        $this->createAccountWithBalance($user, 10_000, frozen: true);

        $subscription = $this->makeSubscription($user);

        $result = $this->service->chargeInitialPeriod($subscription);

        $this->assertSame('failed', $result->result);
        $this->assertSame('ACCOUNT_FROZEN', $result->reason);

        $this->assertDatabaseHas('card_subscription_billing_attempts', [
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Failed->value,
            'failure_reason'       => 'ACCOUNT_FROZEN',
        ]);

        $this->assertDatabaseMissing('card_fees', [
            'related_entity_id' => $subscription->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 3 — chargeInitialPeriod: payer account not found
    // -------------------------------------------------------------------------

    #[Test]
    public function chargeInitialPeriod_returns_failed_with_payer_account_not_found_when_no_account_exists(): void
    {
        $this->requireDatabase();

        // User exists but has NO account in DB
        $user = $this->makeUser();

        $subscription = $this->makeSubscription($user);

        $result = $this->service->chargeInitialPeriod($subscription);

        $this->assertSame('failed', $result->result);
        $this->assertSame('PAYER_ACCOUNT_NOT_FOUND', $result->reason);

        $this->assertDatabaseHas('card_subscription_billing_attempts', [
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Failed->value,
            'failure_reason'       => 'PAYER_ACCOUNT_NOT_FOUND',
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 4 — chargeInitialPeriod: success
    // -------------------------------------------------------------------------

    #[Test]
    public function chargeInitialPeriod_returns_success_and_creates_attempt_and_fee_when_balance_is_sufficient(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();
        $this->createAccountWithBalance($user, 10_000); // 100.00 SZL

        $subscription = $this->makeSubscription($user, ['monthly_fee' => '49.00']);

        $result = $this->service->chargeInitialPeriod($subscription);

        $this->assertSame('success', $result->result);
        $this->assertSame(4900, $result->amountCents);

        $this->assertDatabaseHas('card_subscription_billing_attempts', [
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Success->value,
        ]);

        $this->assertDatabaseHas('card_fees', [
            'related_entity_id'   => $subscription->id,
            'related_entity_type' => CardSubscription::class,
            'fee_type'            => 'subscription',
            'status'              => 'charged',
        ]);

        $this->assertSame(1, $this->withdrawCallCount, 'withdraw() should be called exactly once on a successful charge');
    }

    // -------------------------------------------------------------------------
    // Scenario 5 — billRenewal: success advances period
    // -------------------------------------------------------------------------

    #[Test]
    public function billRenewal_advances_billing_period_on_success(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();
        $this->createAccountWithBalance($user, 10_000);

        $nextBillingDate = now()->addMonth();

        $subscription = $this->makeSubscription($user, [], [
            'next_billing_date'    => $nextBillingDate,
            'current_period_start' => now()->subMonth(),
            'current_period_end'   => $nextBillingDate,
        ]);

        $result = $this->service->billRenewal($subscription);

        $this->assertSame('success', $result->result);

        $this->assertDatabaseHas('card_subscription_billing_attempts', [
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Success->value,
        ]);

        $this->assertDatabaseHas('card_fees', [
            'related_entity_id' => $subscription->id,
        ]);

        $subscription->refresh();
        $this->assertSame(0, $subscription->failed_payment_count);

        // next_billing_date should have moved forward by ~1 month
        $expectedNextBilling = $nextBillingDate->copy()->addMonth();
        $this->assertTrue(
            $subscription->next_billing_date->diffInSeconds($expectedNextBilling) < 5,
            'next_billing_date should advance by 1 month after a successful renewal'
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 6 — billRenewal: failure on past_due with expired grace → suspended
    // -------------------------------------------------------------------------

    #[Test]
    public function billRenewal_suspends_past_due_subscription_when_grace_period_has_expired(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();
        $this->createAccountWithBalance($user, 0); // insufficient

        $subscription = $this->makeSubscription($user, [], [
            'status'               => CardSubscriptionStatus::PastDue->value,
            'grace_period_ends_at' => now()->subDay(), // grace expired
            'failed_payment_count' => 1,
        ]);

        $result = $this->service->billRenewal($subscription);

        $this->assertSame('failed', $result->result);

        $subscription->refresh();
        $this->assertSame(CardSubscriptionStatus::Suspended, $subscription->status);
    }

    // -------------------------------------------------------------------------
    // Scenario 7 — billRenewal: success on past_due restores to active
    // -------------------------------------------------------------------------

    #[Test]
    public function billRenewal_restores_past_due_subscription_to_active_on_success(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();
        $this->createAccountWithBalance($user, 10_000);

        $subscription = $this->makeSubscription($user, [], [
            'status'               => CardSubscriptionStatus::PastDue->value,
            'failed_payment_count' => 1,
            'grace_period_ends_at' => now()->addDay(), // grace still active
        ]);

        $result = $this->service->billRenewal($subscription);

        $this->assertSame('success', $result->result);

        $subscription->refresh();
        $this->assertSame(CardSubscriptionStatus::Active, $subscription->status);
        $this->assertSame(0, $subscription->failed_payment_count);
    }

    // -------------------------------------------------------------------------
    // Scenario 8 — retryFailedPayment: success
    // -------------------------------------------------------------------------

    #[Test]
    public function retryFailedPayment_returns_success_and_creates_attempt_when_balance_is_sufficient(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();
        $this->createAccountWithBalance($user, 10_000);

        $subscription = $this->makeSubscription($user, [], [
            'status'               => CardSubscriptionStatus::PastDue->value,
            'failed_payment_count' => 1,
        ]);

        $result = $this->service->retryFailedPayment($subscription);

        $this->assertSame('success', $result->result);

        $this->assertDatabaseHas('card_subscription_billing_attempts', [
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Success->value,
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 9 — handleSuccessfulPayment: period roll
    // -------------------------------------------------------------------------

    #[Test]
    public function handleSuccessfulPayment_rolls_period_dates_forward_by_one_month(): void
    {
        $this->requireDatabase();

        $nextBillingDate = Carbon::parse('2025-01-15');

        $user = $this->makeUser();
        $subscription = $this->makeSubscription($user, [], [
            'next_billing_date'    => $nextBillingDate,
            'current_period_start' => $nextBillingDate->copy()->subMonth(),
            'current_period_end'   => $nextBillingDate,
        ]);

        $this->service->handleSuccessfulPayment($subscription, BillingAttemptResult::success(null, 4900));

        $subscription->refresh();

        // current_period_start = old next_billing_date = 2025-01-15
        $this->assertSame('2025-01-15', $subscription->current_period_start->toDateString());
        // current_period_end = 2025-02-15
        $this->assertSame('2025-02-15', $subscription->current_period_end->toDateString());
        // next_billing_date = 2025-02-15
        $this->assertSame('2025-02-15', $subscription->next_billing_date->toDateString());
    }

    // -------------------------------------------------------------------------
    // Scenario 10 — handleFailedPayment: active → past_due
    // -------------------------------------------------------------------------

    #[Test]
    public function handleFailedPayment_transitions_active_subscription_to_past_due(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();
        $callTime = now();

        $subscription = $this->makeSubscription($user, [], [
            'status'               => CardSubscriptionStatus::Active->value,
            'failed_payment_count' => 0,
        ]);

        $this->service->handleFailedPayment($subscription, BillingAttemptResult::failed('INSUFFICIENT_FUNDS'));

        $subscription->refresh();

        $this->assertSame(CardSubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->failed_payment_count);
        $this->assertNotNull($subscription->grace_period_ends_at);

        $this->assertTrue(
            $subscription->grace_period_ends_at->between(
                $callTime->copy()->addDays(3)->subSeconds(5),
                $callTime->copy()->addDays(3)->addSeconds(5),
            ),
            'grace_period_ends_at should be approximately 3 days from call time'
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 11 — handleFailedPayment: suspended ≥14 days → cancelled
    // -------------------------------------------------------------------------

    #[Test]
    public function handleFailedPayment_cancels_subscription_and_cards_when_suspended_for_14_days_or_more(): void
    {
        $this->requireDatabase();

        $user = $this->makeUser();

        $subscription = $this->makeSubscription($user, [], [
            'status'       => CardSubscriptionStatus::Suspended->value,
            'suspended_at' => now()->subDays(15),
        ]);

        // Insert 2 active cards
        Card::factory()->create([
            'user_id'              => $subscription->subscriber_user_id,
            'card_subscription_id' => $subscription->id,
            'status'               => 'active',
            'kind'                 => 'virtual',
        ]);
        Card::factory()->create([
            'user_id'              => $subscription->subscriber_user_id,
            'card_subscription_id' => $subscription->id,
            'status'               => 'active',
            'kind'                 => 'virtual',
        ]);

        $this->service->handleFailedPayment($subscription, BillingAttemptResult::failed('INSUFFICIENT_FUNDS'));

        $subscription->refresh();

        $this->assertSame(CardSubscriptionStatus::Cancelled, $subscription->status);

        $cancelledCards = Card::where('card_subscription_id', $subscription->id)
            ->where('status', 'cancelled')
            ->count();
        $this->assertSame(2, $cancelledCards);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a plain user (no special KYC required for billing tests).
     */
    private function makeUser(): User
    {
        return User::factory()->create();
    }

    /**
     * Create an Account + AccountBalance row for the given user.
     *
     * @param int  $balanceCents  Balance in cents (e.g. 10_000 = 100.00 SZL)
     * @param bool $frozen        Whether the account is frozen
     */
    private function createAccountWithBalance(User $user, int $balanceCents, bool $frozen = false): Account
    {
        // Ensure the SZL asset row exists — account_balances.asset_code has a FK to assets.code
        DB::table('assets')->insertOrIgnore([
            'code'         => 'SZL',
            'name'         => 'Swazi Lilangeni',
            'type'         => 'fiat',
            'precision'    => 2,
            'is_active'    => true,
            'is_basket'    => false,
            'is_tradeable' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $account = Account::create([
            'uuid'      => (string) \Illuminate\Support\Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => $frozen,
            'name'      => 'Test Account',
        ]);

        AccountBalance::updateOrCreate(
            ['account_uuid' => $account->uuid, 'asset_code' => 'SZL'],
            ['balance' => $balanceCents]
        );

        return $account;
    }

    /**
     * Create a persisted CardSubscription for the given user.
     *
     * @param array<string, mixed> $planOverrides
     * @param array<string, mixed> $subscriptionOverrides
     */
    private function makeSubscription(
        User $user,
        array $planOverrides = [],
        array $subscriptionOverrides = [],
    ): CardSubscription {
        $plan = CardPlan::factory()->create(array_merge([
            'monthly_fee' => '49.00',
            'active'      => true,
        ], $planOverrides));

        $subscription = CardSubscription::factory()->create(array_merge([
            'subscriber_user_id'   => $user->id,
            'payer_user_id'        => $user->id,
            'card_plan_id'         => $plan->id,
            'status'               => CardSubscriptionStatus::Active->value,
            'current_period_start' => now()->subMonth(),
            'current_period_end'   => now()->addMonth(),
            'next_billing_date'    => now()->addMonth(),
            'failed_payment_count' => 0,
        ], $subscriptionOverrides));

        // Eager-load payer so the service can read $subscription->payer->uuid
        $subscription->load('payer', 'plan');

        return $subscription;
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

        foreach (['card_plans', 'card_subscriptions', 'card_subscription_billing_attempts', 'card_fees'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->markTestSkipped(
                    "Table `{$table}` does not exist — run Cards migrations first: " .
                    'php artisan migrate --path=database/migrations/tenant/ --force'
                );
            }
        }
    }
}
