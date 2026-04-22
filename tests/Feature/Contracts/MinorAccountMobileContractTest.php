<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorAccountMobileContractTest extends BaseTestCase
{
    use CreatesApplication;

    private User $guardian;

    private Account $personalAccount;

    private Account $minorAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $tenantId,
            'name'          => 'Minor Account Contract Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->guardian = User::factory()->create([
            'name'            => 'Parent User',
            'email'           => 'parent-' . Str::lower((string) Str::uuid()) . '@example.com',
            'dial_code'       => '+268',
            'mobile'          => '76' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'password'        => Hash::make('secret-pass'),
            'transaction_pin' => Hash::make('1234'),
        ]);

        $child = User::factory()->create([
            'name' => 'Child User',
        ]);

        $this->personalAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid,
            'type'      => 'personal',
            'name'      => 'Parent Personal',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $child->uuid,
            'type'              => 'minor',
            'name'              => 'Jamie',
            'tier'              => 'grow',
            'permission_level'  => 3,
            'parent_account_id' => $this->personalAccount->uuid,
        ]);

        AccountMembership::query()->create([
            'user_uuid'          => $this->guardian->uuid,
            'tenant_id'          => $tenantId,
            'account_uuid'       => $this->personalAccount->uuid,
            'account_type'       => 'personal',
            'role'               => 'owner',
            'status'             => 'active',
            'display_name'       => 'Parent Personal',
            'verification_tier'  => 'verified',
            'capabilities'       => [],
            'joined_at'          => now(),
        ]);

        AccountMembership::query()->create([
            'user_uuid'          => $this->guardian->uuid,
            'tenant_id'          => $tenantId,
            'account_uuid'       => $this->minorAccount->uuid,
            'account_type'       => 'minor',
            'role'               => 'guardian',
            'status'             => 'active',
            'display_name'       => 'Jamie',
            'verification_tier'  => 'basic',
            'capabilities'       => [],
            'joined_at'          => now(),
        ]);
    }

    #[Test]
    public function mobile_login_includes_the_canonical_minor_account_payload(): void
    {
        $response = $this->postJson('/api/auth/mobile/login', [
            'dial_code' => '+268',
            'mobile'    => $this->guardian->mobile,
            'pin'       => '1234',
        ]);

        $response->assertOk();

        $minorAccount = collect($response->json('data.accounts'))
            ->firstWhere('account_uuid', $this->minorAccount->uuid);

        $this->assertEquals([
            'account_uuid'       => $this->minorAccount->uuid,
            'account_type'       => 'minor',
            'role'               => 'guardian',
            'display_name'       => 'Jamie',
            'account_tier'       => 'grow',
            'permission_level'   => 3,
            'parent_account_uuid'=> $this->personalAccount->uuid,
        ], array_intersect_key($minorAccount, array_flip([
            'account_uuid',
            'account_type',
            'role',
            'display_name',
            'account_tier',
            'permission_level',
            'parent_account_uuid',
        ])));
    }

    #[Test]
    public function auth_user_payload_includes_the_canonical_minor_account_payload(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/auth/user');

        $response->assertOk();

        $minorAccount = collect($response->json('data.accounts'))
            ->firstWhere('account_uuid', $this->minorAccount->uuid);

        $this->assertSame('guardian', $minorAccount['role'] ?? null);
        $this->assertSame('grow', $minorAccount['account_tier'] ?? null);
        $this->assertSame(3, $minorAccount['permission_level'] ?? null);
        $this->assertSame($this->personalAccount->uuid, $minorAccount['parent_account_uuid'] ?? null);
    }

    #[Test]
    public function account_index_includes_the_canonical_minor_account_payload(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/accounts');

        $response->assertOk();

        $minorAccount = collect($response->json('data'))
            ->firstWhere('account_uuid', $this->minorAccount->uuid);

        $this->assertSame('Jamie', $minorAccount['display_name'] ?? null);
        $this->assertSame('grow', $minorAccount['account_tier'] ?? null);
        $this->assertSame(3, $minorAccount['permission_level'] ?? null);
        $this->assertSame($this->personalAccount->uuid, $minorAccount['parent_account_uuid'] ?? null);
    }
}
