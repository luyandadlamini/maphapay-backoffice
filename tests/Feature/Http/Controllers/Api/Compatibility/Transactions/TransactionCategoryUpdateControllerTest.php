<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Transactions;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\Asset;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class TransactionCategoryUpdateControllerTest extends ControllerTestCase
{
    private const ROUTE_PREFIX = '/api/transactions';

    protected function setUp(): void
    {
        parent::setUp();

        config(['maphapay_migration.enable_transaction_history' => true]);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        if (! Schema::hasColumn('transaction_projections', 'user_category_slug')) {
            Schema::table('transaction_projections', function (Blueprint $table): void {
                $table->string('analytics_bucket')->nullable()->after('status');
                $table->boolean('budget_eligible')->nullable()->after('analytics_bucket');
                $table->string('source_domain')->nullable()->after('budget_eligible');
                $table->string('system_category_slug')->nullable()->after('source_domain');
                $table->string('user_category_slug')->nullable()->after('system_category_slug');
                $table->string('effective_category_slug')->nullable()->after('user_category_slug');
                $table->string('categorization_source')->nullable()->after('effective_category_slug');
            });
        }
    }

    /** @return array{0: User, 1: Account} */
    private function createUserWithAccount(): array
    {
        $user = User::factory()->create([
            'kyc_status' => 'approved',
        ]);

        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        return [$user, $account];
    }

    #[Test]
    public function test_owner_can_recategorize_a_completed_outgoing_peer_transfer(): void
    {
        [$user, $account] = $this->createUserWithAccount();

        $transaction = TransactionProjection::factory()->create([
            'account_uuid'            => $account->uuid,
            'asset_code'              => 'SZL',
            'type'                    => 'transfer_out',
            'subtype'                 => 'send_money',
            'description'             => 'Lunch split',
            'reference'               => 'REF-CAT-001',
            'status'                  => 'completed',
            'analytics_bucket'        => 'expense',
            'budget_eligible'         => true,
            'source_domain'           => 'p2p',
            'system_category_slug'    => 'peer_transfer',
            'effective_category_slug' => 'peer_transfer',
            'categorization_source'   => 'system',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->patchJson(self::ROUTE_PREFIX . '/' . $transaction->uuid . '/category', [
            'category_slug' => 'food',
        ])
            ->assertOk()
            ->assertJsonPath('data.category_slug', 'food')
            ->assertJsonPath('data.category_source', 'user');

        $this->assertDatabaseHas('transaction_projections', [
            'uuid'                    => $transaction->uuid,
            'user_category_slug'      => 'food',
            'effective_category_slug' => 'food',
            'categorization_source'   => 'user',
        ]);
    }
}
