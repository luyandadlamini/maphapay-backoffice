<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Transactions;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\Asset;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class TransactionHistoryControllerTest extends ControllerTestCase
{
    private const ROUTE = '/api/transactions';

    protected function setUp(): void
    {
        parent::setUp();

        config(['maphapay_migration.enable_transaction_history' => true]);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{User, Account} */
    private function makeUserWithAccount(): array
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

    // ── Flag guard ───────────────────────────────────────────────────────────

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config(['maphapay_migration.enable_transaction_history' => false]);

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->getJson(self::ROUTE)->assertNotFound();
    }

    // ── Empty account ────────────────────────────────────────────────────────

    #[Test]
    public function test_returns_empty_list_when_user_has_no_account(): void
    {
        $user = User::factory()->create(['kyc_status' => 'approved']);
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson(self::ROUTE);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'transactions')
            ->assertJsonPath('data.transactions.data', [])
            ->assertJsonPath('data.transactions.total', 0)
            ->assertJsonPath('data.subtypes', []);
    }

    // ── Canonical field names ────────────────────────────────────────────────

    #[Test]
    public function test_returns_canonical_domain_field_names(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'deposit',
            'subtype'      => 'send_money',
            'description'  => 'Payment from Alice',
            'reference'    => 'REF-001',
            'amount'       => 1050, // 1050 minor units = E10.50
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson(self::ROUTE);
        $response->assertOk();

        $row = $response->json('data.transactions.data.0');

        // Canonical field names — no legacy aliases
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('reference', $row);
        $this->assertArrayHasKey('description', $row);
        $this->assertArrayHasKey('amount', $row);
        $this->assertArrayHasKey('type', $row);
        $this->assertArrayHasKey('subtype', $row);
        $this->assertArrayHasKey('asset_code', $row);
        $this->assertArrayHasKey('direction', $row);
        $this->assertArrayHasKey('analytics_bucket', $row);
        $this->assertArrayHasKey('budget_eligible', $row);
        $this->assertArrayHasKey('source_domain', $row);
        $this->assertArrayHasKey('category_slug', $row);
        $this->assertArrayHasKey('category_label', $row);
        $this->assertArrayHasKey('category_source', $row);
        $this->assertArrayHasKey('editable_category', $row);
        $this->assertArrayHasKey('created_at', $row);

        // No legacy field names
        $this->assertArrayNotHasKey('trx', $row);
        $this->assertArrayNotHasKey('trx_type', $row);
        $this->assertArrayNotHasKey('details', $row);
        $this->assertArrayNotHasKey('remark', $row);

        // Correct values
        $this->assertSame('REF-001', $row['reference']);
        $this->assertSame('Payment from Alice', $row['description']);
        $this->assertSame('10.50', $row['amount']);
        $this->assertSame('deposit', $row['type']);
        $this->assertSame('send_money', $row['subtype']);
        $this->assertSame('SZL', $row['asset_code']);
        $this->assertSame('in', $row['direction']);
        $this->assertSame('income', $row['analytics_bucket']);
        $this->assertFalse($row['budget_eligible']);
        $this->assertSame('p2p', $row['source_domain']);
        $this->assertSame('peer_transfer', $row['category_slug']);
        $this->assertSame('Peer transfer', $row['category_label']);
        $this->assertSame('system', $row['category_source']);
        $this->assertFalse($row['editable_category']);
    }

    #[Test]
    public function test_subtypes_list_returned_instead_of_remarks(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'subtype'      => 'send_money',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'subtype'      => 'request_money',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'subtype'      => 'send_money', // duplicate → appears only once
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson(self::ROUTE)->assertOk();

        // `subtypes` key present; `remarks` key absent
        $data = $response->json('data');
        $this->assertArrayHasKey('subtypes', $data);
        $this->assertArrayNotHasKey('remarks', $data);

        $subtypes = $response->json('data.subtypes');
        $this->assertCount(2, $subtypes);
        $this->assertContains('send_money', $subtypes);
        $this->assertContains('request_money', $subtypes);
    }

    // ── Pagination ───────────────────────────────────────────────────────────

    #[Test]
    public function test_pagination_structure_is_present(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->count(3)->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson(self::ROUTE . '?page=1');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'transactions' => ['data', 'current_page', 'last_page', 'next_page_url', 'total'],
                    'subtypes',
                ],
            ]);

        $this->assertSame(3, $response->json('data.transactions.total'));
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_type_filter_uses_domain_type_values(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'deposit',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'withdrawal',
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $data = $this->getJson(self::ROUTE . '?type=deposit')->assertOk()->json('data.transactions.data');
        $this->assertCount(1, $data);
        $this->assertSame('deposit', $data[0]['type']);
    }

    #[Test]
    public function test_subtype_filter_replaces_legacy_remark_filter(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'subtype'      => 'send_money',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'subtype'      => 'request_money',
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $data = $this->getJson(self::ROUTE . '?subtype=send_money')->assertOk()->json('data.transactions.data');
        $this->assertCount(1, $data);
        $this->assertSame('send_money', $data[0]['subtype']);
    }

    #[Test]
    public function test_search_filter_matches_description_and_reference(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'description'  => 'Alice sent you money',
            'reference'    => 'REF-ALICE',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'description'  => 'Unrelated transaction',
            'reference'    => 'REF-OTHER',
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $data = $this->getJson(self::ROUTE . '?search=Alice')->assertOk()->json('data.transactions.data');
        $this->assertCount(1, $data);
        $this->assertSame('Alice sent you money', $data[0]['description']);
    }

    #[Test]
    public function test_returns_display_narrative_for_send_money_projection(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'transfer_out',
            'subtype'      => 'send_money',
            'description'  => 'Transfer: REF-LIHLE',
            'reference'    => 'REF-LIHLE',
            'status'       => 'completed',
            'metadata'     => [
                'display' => [
                    'counterparty_name' => 'Lihle',
                    'counterparty_role' => 'recipient',
                    'title' => 'Sent to Lihle',
                    'subtitle' => 'Peer transfer',
                    'note_preview' => 'Lunch money',
                    'reference_visible' => false,
                ],
            ],
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $row = $this->getJson(self::ROUTE)->assertOk()->json('data.transactions.data.0');

        $this->assertSame([
            'title' => 'Sent to Lihle',
            'subtitle' => 'Peer transfer',
            'counterparty_name' => 'Lihle',
            'counterparty_role' => 'recipient',
            'note_preview' => 'Lunch money',
            'reference_visible' => false,
        ], $row['display']);
    }

    #[Test]
    public function test_search_filter_matches_display_title_counterparty_and_note(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'transfer_out',
            'subtype'      => 'send_money',
            'description'  => 'Transfer: REF-LIHLE',
            'reference'    => 'REF-LIHLE',
            'status'       => 'completed',
            'metadata'     => [
                'display' => [
                    'counterparty_name' => 'Lihle',
                    'counterparty_role' => 'recipient',
                    'title' => 'Sent to Lihle',
                    'subtitle' => 'Peer transfer',
                    'note_preview' => 'Lunch money',
                    'reference_visible' => false,
                ],
            ],
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'description'  => 'Unrelated transaction',
            'reference'    => 'REF-OTHER',
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->assertCount(1, $this->getJson(self::ROUTE . '?search=Sent to')->assertOk()->json('data.transactions.data'));
        $this->assertCount(1, $this->getJson(self::ROUTE . '?search=Lihle')->assertOk()->json('data.transactions.data'));
        $this->assertCount(1, $this->getJson(self::ROUTE . '?search=Lunch')->assertOk()->json('data.transactions.data'));
    }

    #[Test]
    public function test_older_rows_without_display_metadata_return_null_display(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'description'  => 'Legacy transfer row',
            'reference'    => 'REF-LEGACY',
            'status'       => 'completed',
            'metadata'     => null,
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $row = $this->getJson(self::ROUTE)->assertOk()->json('data.transactions.data.0');
        $this->assertNull($row['display']);
    }

    #[Test]
    public function test_search_filter_matches_transaction_uuid(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        $transaction = TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'description'  => 'Pocket transfer',
            'reference'    => null,
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $data = $this->getJson(self::ROUTE . '?search=' . $transaction->uuid)->assertOk()->json('data.transactions.data');
        $this->assertCount(1, $data);
        $this->assertSame((string) $transaction->uuid, $data[0]['id']);
    }

    #[Test]
    public function test_savings_transactions_return_savings_classification_fields(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'withdrawal',
            'subtype'      => 'pocket_deposit',
            'description'  => 'Savings pocket: Holiday',
            'status'       => 'completed',
            'metadata'     => [
                'source' => 'pocket_transfer',
                'direction' => 'to_pocket',
                'pocket_uuid' => 'pocket-123',
            ],
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $row = $this->getJson(self::ROUTE)->assertOk()->json('data.transactions.data.0');

        $this->assertSame('out', $row['direction']);
        $this->assertSame('savings', $row['analytics_bucket']);
        $this->assertFalse($row['budget_eligible']);
        $this->assertSame('savings', $row['source_domain']);
        $this->assertSame('pocket_deposit', $row['subtype']);
    }

    // ── Isolation + pending exclusion ────────────────────────────────────────

    #[Test]
    public function test_only_returns_own_completed_transactions(): void
    {
        [$userA, $accountA] = $this->makeUserWithAccount();
        [$userB, $accountB] = $this->makeUserWithAccount();

        TransactionProjection::factory()->count(3)->create([
            'account_uuid' => $accountA->uuid,
            'asset_code'   => 'SZL',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->count(5)->create([
            'account_uuid' => $accountB->uuid,
            'asset_code'   => 'SZL',
            'status'       => 'completed',
        ]);
        // pending row for userA — must be excluded
        TransactionProjection::factory()->create([
            'account_uuid' => $accountA->uuid,
            'asset_code'   => 'SZL',
            'status'       => 'pending',
        ]);

        Sanctum::actingAs($userA, ['read', 'write', 'delete']);

        $this->assertSame(3, $this->getJson(self::ROUTE)->assertOk()->json('data.transactions.total'));
    }
}
