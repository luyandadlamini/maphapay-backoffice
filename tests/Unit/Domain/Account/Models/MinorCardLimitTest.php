<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Models;

use App\Domain\Account\Models\MinorCardLimit;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\CreatesApplication;

class MinorCardLimitTest extends BaseTestCase
{
    use CreatesApplication;

    #[\PHPUnit\Framework\Attributes\Test]
    public function valid_limits_pass_validation(): void
    {
        $limit = new MinorCardLimit([
            'daily_limit' => 100.00,
            'monthly_limit' => 3000.00,
            'single_transaction_limit' => 50.00,
            'is_active' => true,
        ]);

        expect($limit->isValid())->toBeTrue();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function daily_limit_exceeding_monthly_limit_throws(): void
    {
        $limit = new MinorCardLimit([
            'daily_limit' => 5000.00,
            'monthly_limit' => 3000.00,
            'single_transaction_limit' => 50.00,
            'is_active' => true,
        ]);

        expect(fn () => $limit->validateHierarchy())
            ->toThrow(\InvalidArgumentException::class, 'Daily limit cannot exceed monthly limit');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function single_transaction_times_thirty_exceeding_monthly_limit_throws(): void
    {
        $limit = new MinorCardLimit([
            'daily_limit' => 100.00,
            'monthly_limit' => 1000.00,
            'single_transaction_limit' => 100.00,
            'is_active' => true,
        ]);

        expect(fn () => $limit->validateHierarchy())
            ->toThrow(\InvalidArgumentException::class, 'Single transaction limit x 30 cannot exceed monthly limit');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function zero_daily_limit_throws(): void
    {
        $limit = new MinorCardLimit([
            'daily_limit' => 0,
            'monthly_limit' => 3000.00,
            'single_transaction_limit' => 50.00,
            'is_active' => true,
        ]);

        expect(fn () => $limit->validateHierarchy())
            ->toThrow(\InvalidArgumentException::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function zero_monthly_limit_throws(): void
    {
        $limit = new MinorCardLimit([
            'daily_limit' => 100.00,
            'monthly_limit' => 0,
            'single_transaction_limit' => 50.00,
            'is_active' => true,
        ]);

        expect(fn () => $limit->validateHierarchy())
            ->toThrow(\InvalidArgumentException::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function negative_limits_throw(): void
    {
        $limit = new MinorCardLimit([
            'daily_limit' => -100.00,
            'monthly_limit' => 3000.00,
            'single_transaction_limit' => 50.00,
            'is_active' => true,
        ]);

        expect(fn () => $limit->validateHierarchy())
            ->toThrow(\InvalidArgumentException::class);
    }
}