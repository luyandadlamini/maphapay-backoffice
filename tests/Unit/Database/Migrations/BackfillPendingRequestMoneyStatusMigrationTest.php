<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Migrations;

use App\Models\MoneyRequest;
use App\Models\User;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Large]
class BackfillPendingRequestMoneyStatusMigrationTest extends TestCase
{
    #[Test]
    public function it_backfills_legacy_awaiting_otp_request_rows_to_pending(): void
    {
        $requester = User::factory()->create();
        $recipient = User::factory()->create();

        $moneyRequest = MoneyRequest::query()->create([
            'id'                => (string) \Illuminate\Support\Str::uuid(),
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '15.00',
            'asset_code'        => 'SZL',
            'status'            => MoneyRequest::STATUS_AWAITING_OTP,
        ]);

        $migration = require base_path('database/migrations/2026_04_03_180000_backfill_pending_request_money_status.php');
        $migration->up();

        $this->assertDatabaseHas('money_requests', [
            'id'     => $moneyRequest->id,
            'status' => MoneyRequest::STATUS_PENDING,
        ]);
    }
}
