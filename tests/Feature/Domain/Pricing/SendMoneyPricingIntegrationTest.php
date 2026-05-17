<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Pricing;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Pricing\Models\FeeEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

/**
 * Verifies that the pricing engine is wired into SendMoneyStoreController
 * behind the 'pricing.pricing_engine_enabled' feature flag.
 *
 * Scope: VERIFICATION_NONE path only (amount < 100 SZL step-up threshold,
 * no transaction PIN). This is the only settlement that completes inside
 * the store controller itself.
 */
class SendMoneyPricingIntegrationTest extends ControllerTestCase
{
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        $this->productId = $this->seedProduct('local_transfer_intg');
        $this->seedRule($this->productId, formula: 'fixed', config: ['fixed_minor' => 100]);
    }

    #[Test]
    public function pricing_engine_records_fee_event_when_flag_is_enabled(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'pricing.pricing_engine_enabled'       => true,
        ]);

        [$sender, $recipient] = $this->makeUsersWithAccounts();

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'        => $recipient->email,
            'amount'      => '1.00', // 100 minor — below step-up threshold → VERIFICATION_NONE
            'attestation' => 'trusted-proof',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'none');

        $trx = $response->json('data.trx');
        /** @var string $txnUuid */
        $txnUuid = AuthorizedTransaction::where('trx', $trx)->value('id');

        $this->assertDatabaseHas('fee_events', [
            'transaction_uuid' => $txnUuid,
            'amount_minor'     => 100,
            'source_domain'    => 'send_money',
            'category'         => 'local_transfer',
        ]);
    }

    #[Test]
    public function pricing_engine_skips_fee_event_when_flag_is_disabled(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'pricing.pricing_engine_enabled'       => false,
        ]);

        [$sender, $recipient] = $this->makeUsersWithAccounts();

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $countBefore = FeeEvent::count();

        $response = $this->postJson('/api/send-money/store', [
            'user'        => $recipient->email,
            'amount'      => '1.00',
            'attestation' => 'trusted-proof',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'none');

        $this->assertSame($countBefore, FeeEvent::count());
    }

    // ---------- helpers ----------

    /**
     * @return array{0: User, 1: User}
     */
    private function makeUsersWithAccounts(): array
    {
        $sender = User::factory()->create(['kyc_status' => 'approved']);
        $recipient = User::factory()->create(['kyc_status' => 'approved']);

        Account::factory()->create(['user_uuid' => $sender->uuid, 'frozen' => false]);
        Account::factory()->create(['user_uuid' => $recipient->uuid, 'frozen' => false]);

        return [$sender, $recipient];
    }

    /** @param array<string, mixed> $config */
    private function seedRule(int $productId, string $formula, array $config): int
    {
        return (int) DB::table('pricing_rules')->insertGetId([
            'product_id' => $productId,
            'segment_id' => null,
            'name'       => 'rule-' . uniqid(),
            'formula'    => $formula,
            'config'     => json_encode($config),
            'priority'   => 10,
            'status'     => 'active',
            'version'    => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedProduct(string $code): int
    {
        return (int) DB::table('pricing_products')->insertGetId([
            'code'             => $code,
            'name'             => $code,
            'category'         => 'local_transfer',
            'default_currency' => 'SZL',
            'active'           => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
