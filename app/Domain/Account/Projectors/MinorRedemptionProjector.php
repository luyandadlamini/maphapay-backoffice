<?php

declare(strict_types=1);

namespace App\Domain\Account\Projectors;

use App\Domain\Account\Events\RedemptionApproved;
use App\Domain\Account\Events\RedemptionDeclined;
use App\Domain\Account\Models\MinorRewardRedemption;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class MinorRedemptionProjector extends Projector
{
    public function onRedemptionApproved(RedemptionApproved $event): void
    {
        MinorRewardRedemption::query()
            ->where('id', $event->redemptionId)
            ->update(['status' => 'approved']);
    }

    public function onRedemptionDeclined(RedemptionDeclined $event): void
    {
        MinorRewardRedemption::query()
            ->where('id', $event->redemptionId)
            ->update(['status' => 'declined']);
    }
}
