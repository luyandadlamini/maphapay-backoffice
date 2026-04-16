<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Rules\ValidateMinorAccountPermission;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class ValidateMinorAccountPermissionTest extends BaseTestCase
{
    use CreatesApplication;

    #[Test]
    public function it_rejects_view_only_minor_accounts(): void
    {
        $minorAccount = Account::factory()->create([
            'account_type'     => 'minor',
            'permission_level' => 2,
        ]);

        $validator = Validator::make(
            ['amount' => 100],
            ['amount' => [new ValidateMinorAccountPermission($minorAccount, 'transfer')]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'This minor account cannot perform spending transactions at its current permission level.',
            $validator->errors()->first('amount'),
        );
    }

    #[Test]
    public function it_rejects_blocked_transaction_categories_for_minor_accounts(): void
    {
        $minorAccount = Account::factory()->create([
            'account_type'     => 'minor',
            'permission_level' => 5,
        ]);

        $validator = Validator::make(
            ['amount' => 100],
            ['amount' => [new ValidateMinorAccountPermission($minorAccount, 'gambling')]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'This transaction category is not allowed for minor accounts.',
            $validator->errors()->first('amount'),
        );
    }

    #[Test]
    public function it_rejects_transactions_that_exceed_the_daily_limit(): void
    {
        $minorAccount = Account::factory()->create([
            'account_type'     => 'minor',
            'permission_level' => 3,
        ]);

        TransactionProjection::factory()->create([
            'account_uuid' => $minorAccount->uuid,
            'amount'       => 45000,
            'type'         => 'transfer',
            'status'       => 'completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $validator = Validator::make(
            ['amount' => 10000],
            ['amount' => [new ValidateMinorAccountPermission($minorAccount, 'transfer')]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'This transaction exceeds the daily spending limit for the minor account.',
            $validator->errors()->first('amount'),
        );
    }

    #[Test]
    public function it_rejects_transactions_that_exceed_the_monthly_limit(): void
    {
        $minorAccount = Account::factory()->create([
            'account_type'     => 'minor',
            'permission_level' => 5,
        ]);

        TransactionProjection::factory()->create([
            'account_uuid' => $minorAccount->uuid,
            'amount'       => 995000,
            'type'         => 'transfer',
            'status'       => 'completed',
            'created_at'   => now()->startOfMonth()->addDays(2),
            'updated_at'   => now()->startOfMonth()->addDays(2),
        ]);

        $validator = Validator::make(
            ['amount' => 10000],
            ['amount' => [new ValidateMinorAccountPermission($minorAccount, 'transfer')]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'This transaction exceeds the monthly spending limit for the minor account.',
            $validator->errors()->first('amount'),
        );
    }

    #[Test]
    public function it_allows_transactions_within_limit_for_spend_enabled_minor_accounts(): void
    {
        $minorAccount = Account::factory()->create([
            'account_type'     => 'minor',
            'permission_level' => 6,
        ]);

        TransactionProjection::factory()->create([
            'account_uuid' => $minorAccount->uuid,
            'amount'       => 50000,
            'type'         => 'transfer',
            'status'       => 'completed',
            'created_at'   => now()->subDay(),
            'updated_at'   => now()->subDay(),
        ]);

        $validator = Validator::make(
            ['amount' => 25000],
            ['amount' => [new ValidateMinorAccountPermission($minorAccount, 'transfer')]],
        );

        $this->assertFalse($validator->fails());
    }
}
