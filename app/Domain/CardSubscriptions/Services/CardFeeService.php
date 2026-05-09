<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardFeeStatus;
use App\Domain\CardSubscriptions\Enums\CardFeeType;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardFee;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\CardSubscriptions\ValueObjects\CardFeePreview;
use App\Domain\CardSubscriptions\ValueObjects\CardFeePreviewInput;
use App\Domain\CardSubscriptions\ValueObjects\ReplacementReason;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Support\Carbon;

class CardFeeService
{
    /**
     * Preview the fees that would apply to a hypothetical transaction.
     *
     * Pure function — no DB writes. Returns a breakdown of all applicable fees
     * given the user's current active subscription plan.
     */
    public function previewTransaction(User $user, CardFeePreviewInput $input): CardFeePreview
    {
        $subscription = CardSubscription::query()
            ->where('subscriber_user_id', $user->id)
            ->whereIn('status', [
                CardSubscriptionStatus::Active->value,
                CardSubscriptionStatus::PastDue->value,
            ])
            ->with('plan')
            ->first();

        if ($subscription === null) {
            return CardFeePreview::fromBreakdown($input->amountCents, $input->currency, []);
        }

        /** @var CardPlan $plan */
        $plan = $subscription->plan;

        $amountMajor = number_format($input->amountCents / 100, 2, '.', '');
        $amount      = Money::fromMajorString($amountMajor, $input->currency);

        $breakdown = [];

        // FX markup fee
        $fxFee = $this->calculateFxFee($plan, $input->currency, $amount);
        $fxFeeCents = (int) round((float) $fxFee->amount * 100);
        if ($fxFeeCents > 0) {
            $breakdown['fx_markup'] = $fxFeeCents;
        }

        // ATM withdrawal fee
        if ($input->transactionType === 'atm_withdrawal' && $plan->atm_enabled) {
            $atmFee      = $this->calculateAtmFee($plan, $amount);
            $atmFeeCents = (int) round((float) $atmFee->amount * 100);
            if ($atmFeeCents > 0) {
                $breakdown['atm'] = $atmFeeCents;
            }
        }

        return CardFeePreview::fromBreakdown($input->amountCents, $input->currency, $breakdown);
    }

    /**
     * Calculate the FX markup fee for a cross-currency transaction.
     *
     * SZL and ZAR are treated as the domestic currency pair for Eswatini — no markup applied.
     */
    public function calculateFxFee(CardPlan $plan, string $currency, Money $billingAmount): Money
    {
        if (in_array($currency, ['SZL', 'ZAR'], true)) {
            return Money::zero('SZL');
        }

        return $billingAmount->multiplyBps($plan->fx_markup_bps);
    }

    /**
     * Calculate the ATM withdrawal fee (fixed component + percentage component).
     *
     * Both components are denominated in SZL. The caller is responsible for
     * ensuring the plan has ATM enabled before calling this method.
     */
    public function calculateAtmFee(CardPlan $plan, Money $withdrawalAmount): Money
    {
        $fixed      = Money::fromMajorString((string) $plan->atm_fixed_fee, 'SZL');
        $percentage = $withdrawalAmount->multiplyBps($plan->atm_percentage_fee_bps);

        return $fixed->add($percentage);
    }

    /**
     * Charge the one-time physical card issuance fee for a new card order.
     *
     * Creates a CardFee row in Charged status. The fee amount is taken directly
     * from the order's subscription plan at the time of issuance.
     *
     * TODO: Phase 4 — call LedgerPostingService::post() to debit wallet
     */
    public function chargeIssuanceFee(User $payer, PhysicalCardOrder $order): CardFee
    {
        $plan = $order->subscription->plan;

        /** @var CardFee $fee */
        $fee = CardFee::create([
            'user_id'             => $payer->id,
            'related_entity_id'   => $order->id,
            'related_entity_type' => PhysicalCardOrder::class,
            'fee_type'            => CardFeeType::PhysicalCardIssuance,
            'amount'              => (string) $plan->physical_card_issuance_fee,
            'currency'            => 'SZL',
            'status'              => CardFeeStatus::Charged,
            'charged_at'          => Carbon::now(),
        ]);

        return $fee;
    }

    /**
     * Charge the physical or virtual card replacement fee, subject to plan rules.
     *
     * Expired and fraud-related replacements are free; a waived zero-amount fee
     * row is persisted so the event is auditable. Physical card replacements use
     * the plan's physical_card_replacement_fee. Virtual replacements delegate to
     * chargeVirtualReplacementFee — if that returns null (free monthly reissue),
     * a waived zero-amount row is persisted.
     *
     * TODO: Phase 4 — call LedgerPostingService::post() to debit wallet
     */
    public function chargeReplacementFee(User $payer, Card $card, ReplacementReason $reason): CardFee
    {
        // Expired or fraud replacements are bank-absorbed; record a waived zero fee
        // so the replacement is auditable without creating a chargeable event.
        if ($reason === ReplacementReason::EXPIRED || $reason === ReplacementReason::FRAUD) {
            $feeType = $card->kind === 'virtual'
                ? CardFeeType::VirtualCardReplacement
                : CardFeeType::PhysicalCardReplacement;

            /** @var CardFee $fee */
            $fee = CardFee::create([
                'user_id'             => $payer->id,
                'related_entity_id'   => $card->id,
                'related_entity_type' => Card::class,
                'fee_type'            => $feeType,
                'amount'              => '0.00',
                'currency'            => 'SZL',
                'status'              => CardFeeStatus::Waived,
                'waived_at'           => Carbon::now(),
                'notes'               => 'Waived: replacement reason is ' . $reason->value,
            ]);

            return $fee;
        }

        if ($card->kind === 'virtual') {
            $charged = $this->chargeVirtualReplacementFee($payer, $card);

            if ($charged === null) {
                // Within the free monthly reissue allowance — persist a waived record
                /** @var CardFee $fee */
                $fee = CardFee::create([
                    'user_id'             => $payer->id,
                    'related_entity_id'   => $card->id,
                    'related_entity_type' => Card::class,
                    'fee_type'            => CardFeeType::VirtualCardReplacement,
                    'amount'              => '0.00',
                    'currency'            => 'SZL',
                    'status'              => CardFeeStatus::Waived,
                    'waived_at'           => Carbon::now(),
                    'notes'               => 'Waived: within free monthly reissue allowance',
                ]);

                return $fee;
            }

            return $charged;
        }

        // Physical card replacement
        $subscription = CardSubscription::find($card->card_subscription_id);
        $plan         = $subscription->plan;

        /** @var CardFee $fee */
        $fee = CardFee::create([
            'user_id'             => $payer->id,
            'related_entity_id'   => $card->id,
            'related_entity_type' => Card::class,
            'fee_type'            => CardFeeType::PhysicalCardReplacement,
            'amount'              => (string) $plan->physical_card_replacement_fee,
            'currency'            => 'SZL',
            'status'              => CardFeeStatus::Charged,
            'charged_at'          => Carbon::now(),
        ]);

        return $fee;
    }

    /**
     * Charge the virtual card replacement fee, respecting the plan's monthly free reissue quota.
     *
     * Returns null when the replacement falls within the free allowance (no fee row created).
     * The caller should record a waived row if an audit trail is needed.
     *
     * TODO: Phase 4 — call LedgerPostingService::post() to debit wallet
     */
    public function chargeVirtualReplacementFee(User $payer, Card $card): ?CardFee
    {
        $subscription = CardSubscription::find($card->card_subscription_id);
        $plan         = $subscription->plan;

        $usedThisMonth = CardFee::query()
            ->where('user_id', $payer->id)
            ->where('fee_type', CardFeeType::VirtualCardReplacement->value)
            ->whereNotNull('charged_at')
            ->where('charged_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        if ($usedThisMonth < $plan->free_virtual_reissues_per_month) {
            return null;
        }

        /** @var CardFee $fee */
        $fee = CardFee::create([
            'user_id'             => $payer->id,
            'related_entity_id'   => $card->id,
            'related_entity_type' => Card::class,
            'fee_type'            => CardFeeType::VirtualCardReplacement,
            'amount'              => (string) $plan->virtual_card_replacement_fee,
            'currency'            => 'SZL',
            'status'              => CardFeeStatus::Charged,
            'charged_at'          => Carbon::now(),
        ]);

        return $fee;
    }

    /**
     * Charge the hardcoded E100 chargeback abuse fee against a resolved dispute.
     *
     * The E100 amount is fixed across all paid plans per the product config
     * (01-product-config.md §1). It does not vary by subscription tier.
     *
     * TODO: Phase 4 — call LedgerPostingService::post() to debit wallet
     */
    public function chargeChargebackAbuseFee(User $user, CardDispute $dispute): CardFee
    {
        /** @var CardFee $fee */
        $fee = CardFee::create([
            'user_id'             => $user->id,
            'related_entity_id'   => $dispute->id,
            'related_entity_type' => CardDispute::class,
            'fee_type'            => CardFeeType::ChargebackAbuse,
            'amount'              => '100.00',
            'currency'            => 'SZL',
            'status'              => CardFeeStatus::Charged,
            'charged_at'          => Carbon::now(),
        ]);

        return $fee;
    }

    /**
     * Mark a fee record as waived by an administrator.
     *
     * This does not reverse any ledger entries — ledger reversal is a Phase 4
     * concern handled by CardBillingService.
     */
    public function waiveFee(CardFee $fee, string $reason, User $admin): CardFee
    {
        $fee->status   = CardFeeStatus::Waived;
        $fee->waived_at = Carbon::now();
        $fee->notes    = $reason;
        $fee->save();

        return $fee;
    }

    /**
     * Mark a fee record as refunded by an administrator.
     *
     * This does not reverse any ledger entries — ledger reversal is a Phase 4
     * concern handled by CardBillingService.
     */
    public function refundFee(CardFee $fee, string $reason, User $admin): CardFee
    {
        $fee->status      = CardFeeStatus::Refunded;
        $fee->refunded_at = Carbon::now();
        $fee->notes       = $reason;
        $fee->save();

        return $fee;
    }
}
