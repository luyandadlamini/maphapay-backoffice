<?php

declare(strict_types=1);

namespace App\Domain\FinancialInstitution\Events;

use App\Domain\FinancialInstitution\Models\FinancialInstitutionPartner;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PartnerActivated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly FinancialInstitutionPartner $partner
    ) {
    }
}
