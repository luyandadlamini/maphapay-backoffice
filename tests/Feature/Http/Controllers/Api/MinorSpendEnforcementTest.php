<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\TransactionProjection;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorSpendEnforcementTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    private User $parent;
    private User $child;
    private Account $minorAccount;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->parent = User::factory()->create();
        $this->child  = User::factory()->create();

        $parentAccountUuid = Str::uuid();
        $minorAccountUuid = Str::uuid();

        // Create parent account - use raw insert with only basic columns
        DB::table('accounts')->insert([
            'uuid' => $parentAccountUuid,
            'name' => 'Parent Account',
            'user_uuid' => $this->parent->uuid,
            'balance' => 0,
            'frozen' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create minor account - use raw insert with only columns that exist
        $minorData = [
            'uuid' => $minorAccountUuid,
            'name' => 'Minor Account',
            'user_uuid' => $this->child->uuid,
            'balance' => 0,
            'frozen' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Add optional columns if they exist in the schema
        if (Schema::hasColumn('accounts', 'type')) {
            $minorData['type'] = 'minor';
        }
        if (Schema::hasColumn('accounts', 'tier')) {
            $minorData['tier'] = 'grow';
        }
        if (Schema::hasColumn('accounts', 'permission_level')) {
            $minorData['permission_level'] = 3;
        }
        if (Schema::hasColumn('accounts', 'parent_account_id')) {
            $minorData['parent_account_id'] = $parentAccountUuid;
        }

        DB::table('accounts')->insert($minorData);

        $parentAccount = Account::query()->where('uuid', $parentAccountUuid)->first();
        $this->minorAccount = Account::query()->where('uuid', $minorAccountUuid)->first();

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id' => $this->tenantId, 'name' => 'Test', 'plan' => 'default',
            'team_id' => null, 'trial_ends_at' => null,
            'created_at' => now(), 'updated_at' => now(), 'data' => json_encode([]),
        ]);
        AccountMembership::create([
            'user_uuid' => $this->parent->uuid, 'account_uuid' => $parentAccount->uuid,
            'tenant_id' => $this->tenantId, 'account_type' => 'personal', 'role' => 'owner', 'status' => 'active',
        ]);

        AccountMembership::create([
            'user_uuid' => $this->child->uuid, 'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $this->tenantId, 'account_type' => 'minor', 'role' => 'owner', 'status' => 'active',
        ]);
        AccountMembership::create([
            'user_uuid' => $this->parent->uuid, 'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $this->tenantId, 'account_type' => 'minor', 'role' => 'guardian', 'status' => 'active',
        ]);
    }

    #[Test]
    public function minor_level_1_or_2_cannot_spend_at_all(): void
    {
        // Update the minor account permission level
        if (Schema::hasColumn('accounts', 'permission_level')) {
            $this->minorAccount->update(['permission_level' => 2]);
        }

        $recipient = User::factory()->create();

        // Create recipient account
        DB::table('accounts')->insert([
            'uuid' => Str::uuid(),
            'name' => 'Recipient Account',
            'user_uuid' => $recipient->uuid,
            'balance' => 1000,
            'frozen' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '10.00',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('permission level', strtolower($response->json('message') ?? ''));
    }

    #[Test]
    public function minor_cannot_spend_in_blocked_category(): void
    {
        $recipient = User::factory()->create();

        // Create recipient account
        DB::table('accounts')->insert([
            'uuid' => Str::uuid(),
            'name' => 'Recipient Account',
            'user_uuid' => $recipient->uuid,
            'balance' => 1000,
            'frozen' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'              => $recipient->mobile ?? $recipient->email,
            'amount'            => '10.00',
            'merchant_category' => 'gambling',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not allowed', strtolower($response->json('message') ?? ''));
    }

    #[Test]
    public function minor_cannot_exceed_daily_limit(): void
    {
        // Create a transaction projection directly
        DB::table('transaction_projections')->insert([
            'account_uuid' => $this->minorAccount->uuid,
            'type' => 'debit',
            'amount' => 45_000,
            'status' => 'completed',
            'created_at' => now()->startOfDay()->addHour(),
            'updated_at' => now(),
        ]);

        $recipient = User::factory()->create();

        // Create recipient account
        DB::table('accounts')->insert([
            'uuid' => Str::uuid(),
            'name' => 'Recipient Account',
            'user_uuid' => $recipient->uuid,
            'balance' => 1000,
            'frozen' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '100.00',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('daily', strtolower($response->json('message') ?? ''));
    }

    #[Test]
    public function minor_level_3_within_limits_proceeds_normally(): void
    {
        $recipient = User::factory()->create();

        // Create recipient account
        DB::table('accounts')->insert([
            'uuid' => Str::uuid(),
            'name' => 'Recipient Account',
            'user_uuid' => $recipient->uuid,
            'balance' => 1000,
            'frozen' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '10.00',
        ]);

        $this->assertNotEquals(422, $response->status());
        if ($response->status() === 422) {
            $this->assertStringNotContainsString(
                'permission level',
                strtolower($response->json('message') ?? '')
            );
        }
    }
}
