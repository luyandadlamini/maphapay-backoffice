<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardFee;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\CardSubscriptions\ValueObjects\CardFeePreview;
use App\Domain\CardSubscriptions\ValueObjects\CardFeePreviewInput;
use App\Domain\CardSubscriptions\ValueObjects\ReplacementReason;
use App\Domain\Shared\Money\Money;
use App\Models\User;

class CardFeeService
{
    public function previewTransaction(User $user, CardFeePreviewInput $input): CardFeePreview
    {
        throw new \LogicException('not implemented');
    }

    public function calculateFxFee(CardPlan $plan, string $currency, Money $billingAmount): Money
    {
        throw new \LogicException('not implemented');
    }

    public function calculateAtmFee(CardPlan $plan, Money $withdrawalAmount): Money
    {
        throw new \LogicException('not implemented');
    }

    public function chargeIssuanceFee(User $payer, PhysicalCardOrder $order): CardFee
    {
        throw new \LogicException('not implemented');
    }

    public function chargeReplacementFee(User $payer, Card $card, ReplacementReason $reason): CardFee
    {
        throw new \LogicException('not implemented');
    }

    public function chargeVirtualReplacementFee(User $payer, Card $card): ?CardFee
    {
        throw new \LogicException('not implemented');
    }

    public function chargeChargebackAbuseFee(User $user, CardDispute $dispute): CardFee
    {
        throw new \LogicException('not implemented');
    }

    public function waiveFee(CardFee $fee, string $reason, User $admin): CardFee
    {
        throw new \LogicException('not implemented');
    }

    public function refundFee(CardFee $fee, string $reason, User $admin): CardFee
    {
        throw new \LogicException('not implemented');
    }
}
