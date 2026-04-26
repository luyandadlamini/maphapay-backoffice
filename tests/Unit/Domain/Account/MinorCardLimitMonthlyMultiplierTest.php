<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\Models\MinorCardLimit;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use InvalidArgumentException;
use Tests\CreatesApplication;

class MinorCardLimitMonthlyMultiplierTest extends BaseTestCase
{
    use CreatesApplication;

    #[\PHPUnit\Framework\Attributes\Test]
    public function allows_monthly_limit_that_is_exactly_days_in_month_times_single(): void
    {
        $daysInMonth = now()->daysInMonth;

        $limit = new MinorCardLimit([
            'single_transaction_limit' => 1000,
            'daily_limit'              => 5000,
            'monthly_limit'            => 1000 * $daysInMonth,
        ]);

        $limit->validateHierarchy();

        $this->assertTrue(true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rejects_monthly_limit_that_would_require_more_days_than_are_in_the_current_month(): void
    {
        $daysInMonth = now()->daysInMonth;

        $limit = new MinorCardLimit([
            'single_transaction_limit' => 1000,
            'daily_limit'              => 5000,
            'monthly_limit'            => 1000 * ($daysInMonth + 1),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $limit->validateHierarchy();
    }
}
