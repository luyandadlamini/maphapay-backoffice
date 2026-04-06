<?php

declare(strict_types=1);

namespace App\Domain\Basket\Activities;

use App\Domain\Account\DataObjects\AccountUuid;
use Workflow\Activity;

class DecomposeBasketActivity extends Activity
{
    public function __construct(
        private DecomposeBasketBusinessActivity $businessActivity
    ) {
    }

    /**
     * Execute basket decomposition activity using proper domain pattern.
     */
    public function execute(AccountUuid $accountUuid, string $basketCode, int $amount): array
    {
        return $this->businessActivity->execute($accountUuid, $basketCode, $amount);
    }
}
