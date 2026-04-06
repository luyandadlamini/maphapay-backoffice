<?php

declare(strict_types=1);

namespace App\Domain\FinancialInstitution\Events;

use App\Domain\FinancialInstitution\Models\FinancialInstitutionApplication;
use App\Domain\FinancialInstitution\Models\FinancialInstitutionPartner;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApplicationApproved
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly FinancialInstitutionApplication $application,
        public readonly FinancialInstitutionPartner $partner
    ) {
    }
}
