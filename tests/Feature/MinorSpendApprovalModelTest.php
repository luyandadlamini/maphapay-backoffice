<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Account\Models\MinorSpendApproval;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorSpendApprovalModelTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
    return false;
    }

    #[Test]
    public function minor_spend_approval_table_has_expected_columns(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumns('minor_spend_approvals', [
                'id', 'minor_account_uuid', 'guardian_account_uuid',
                'from_account_uuid', 'to_account_uuid',
                'amount', 'asset_code', 'note',
                'merchant_category', 'status',
                'expires_at', 'decided_at', 'created_at', 'updated_at',
            ])
        );
    }

    #[Test]
    public function minor_spend_approval_can_be_created_and_read(): void
    {
        $approval = MinorSpendApproval::create([
            'minor_account_uuid'    => (string) \Illuminate\Support\Str::uuid(),
            'guardian_account_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'from_account_uuid'     => (string) \Illuminate\Support\Str::uuid(),
            'to_account_uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'amount'                => '150.00',
            'asset_code'            => 'SZL',
            'note'                  => 'Test',
            'merchant_category'     => 'general',
            'status'                => 'pending',
            'expires_at'            => now()->addHours(24),
        ]);

        $this->assertDatabaseHas('minor_spend_approvals', ['id' => $approval->id, 'status' => 'pending']);
    }
}
