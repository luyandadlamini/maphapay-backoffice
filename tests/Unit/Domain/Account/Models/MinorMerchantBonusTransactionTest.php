<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Models;

use App\Domain\Account\Models\MinorMerchantBonusTransaction;
use App\Models\MerchantPartner;
use Tests\TestCase;

class MinorMerchantBonusTransactionTest extends TestCase
{
    public function test_bonus_transaction_can_be_created(): void
    {
        $partner = MerchantPartner::create([
            'name'            => 'Test Merchant',
            'category'        => 'retail',
            'commission_rate' => 10.00,
            'payout_schedule' => 'weekly',
            'is_active'       => true,
        ]);

        $transaction = MinorMerchantBonusTransaction::create([
            'id'                      => 'uuid-test-123',
            'merchant_partner_id'     => $partner->id,
            'minor_account_uuid'      => 'minor-uuid-123',
            'parent_transaction_uuid' => 'trx-456',
            'bonus_points_awarded'    => 5,
            'multiplier_applied'      => 2.0,
            'amount_szl'              => 25.00,
            'status'                  => 'awarded',
        ]);

        $this->assertDatabaseHas('minor_merchant_bonus_transactions', [
            'minor_account_uuid'   => 'minor-uuid-123',
            'bonus_points_awarded' => 5,
        ]);
    }
}
