<?php

declare(strict_types=1);

namespace Tests\Feature\Corporate;

use App\Domain\Corporate\Enums\CorporateCapability;
use App\Domain\Corporate\Services\CorporateApiAdminService;
use App\Domain\Corporate\Services\CorporateMemberService;
use App\Domain\Corporate\Services\CorporateSpendControlService;
use App\Domain\Corporate\Services\CorporateTreasuryOperationsService;
use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CorporateCapabilityEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCorporateSectionSchemaBaseline();
        $this->ensureCapabilityEnforcementSchemaBaseline();
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Test 1: MEMBER_ADMINISTRATION gate enforcement
    // -------------------------------------------------------------------------

    public function test_it_enforces_member_administration_capability_when_assigning_roles(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $member = User::factory()->create();
        $team->users()->attach($member);
        $member->current_team_id = $team->id;
        $member->save();

        $targetUser = User::factory()->create();
        $team->users()->attach($targetUser);

        $service = app(CorporateMemberService::class);

        // Non-holder should throw AuthorizationException
        $thrown = false;
        try {
            $service->assignRole($member, $team, $targetUser, 'accountant');
        } catch (AuthorizationException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Expected AuthorizationException for member without MEMBER_ADMINISTRATION capability');

        // Grant the capability and retry — should succeed
        $team->resolveCorporateProfile()->grantCapabilityToUser(
            $member,
            CorporateCapability::MEMBER_ADMINISTRATION,
            $owner,
        );

        $teamUserRole = $service->assignRole($member, $team, $targetUser, 'accountant');

        $this->assertSame('accountant', $teamUserRole->role);
        $this->assertSame($targetUser->id, $teamUserRole->user_id);
    }

    // -------------------------------------------------------------------------
    // Test 2: Team owner bypasses MEMBER_ADMINISTRATION gate
    // -------------------------------------------------------------------------

    public function test_it_bypasses_member_administration_gate_for_team_owner(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $targetUser = User::factory()->create();
        $team->users()->attach($targetUser);

        $service = app(CorporateMemberService::class);

        // Owner should NOT throw — ownsTeam returns true in the gate
        $teamUserRole = $service->assignRole($owner, $team, $targetUser, 'operations_manager');

        $this->assertSame('operations_manager', $teamUserRole->role);
        $this->assertSame($targetUser->id, $teamUserRole->user_id);
    }

    // -------------------------------------------------------------------------
    // Test 3: SPEND_CONTROL_ADMINISTRATION gate enforcement
    // -------------------------------------------------------------------------

    public function test_it_enforces_spend_control_administration_capability(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $member = User::factory()->create();
        $team->users()->attach($member);
        $member->current_team_id = $team->id;
        $member->save();

        $service = app(CorporateSpendControlService::class);

        // Non-holder should throw
        $thrown = false;
        try {
            $service->configureAgentSpendingLimit($member, $team, 'agent-abc', 100000, 10000);
        } catch (AuthorizationException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Expected AuthorizationException for member without SPEND_CONTROL_ADMINISTRATION');

        // Grant capability and retry — should persist DB record
        $team->resolveCorporateProfile()->grantCapabilityToUser(
            $member,
            CorporateCapability::SPEND_CONTROL_ADMINISTRATION,
            $owner,
        );

        $limit = $service->configureAgentSpendingLimit($member, $team, 'agent-abc', 100000, 10000);

        $this->assertSame(100000, $limit->daily_limit);
        $this->assertSame(10000, $limit->per_tx_limit);
        $this->assertSame('agent-abc', $limit->agent_id);
        $this->assertSame($team->id, $limit->team_id);

        // Verify DB persistence
        $this->assertDatabaseHas('mpp_spending_limits', [
            'agent_id'     => 'agent-abc',
            'team_id'      => $team->id,
            'daily_limit'  => 100000,
            'per_tx_limit' => 10000,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 4: API_ADMINISTRATION gate enforcement on revocation
    // -------------------------------------------------------------------------

    public function test_it_enforces_api_administration_capability_on_api_key_revocation(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $member = User::factory()->create();
        $team->users()->attach($member);
        $member->current_team_id = $team->id;
        $member->save();

        /** @var ApiKey $apiKey */
        $apiKey = ApiKey::create([
            'uuid'       => \Illuminate\Support\Str::uuid()->toString(),
            'user_uuid'  => $owner->uuid,
            'name'       => 'Test Key',
            'key_prefix' => 'test1234',
            'key_hash'   => 'dummy_hash_for_test',
            'is_active'  => true,
        ]);

        $service = app(CorporateApiAdminService::class);

        // Non-holder should throw
        $thrown = false;
        try {
            $service->revokeApiKey($member, $team, $apiKey);
        } catch (AuthorizationException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Expected AuthorizationException for member without API_ADMINISTRATION');

        // Grant capability and retry
        $team->resolveCorporateProfile()->grantCapabilityToUser(
            $member,
            CorporateCapability::API_ADMINISTRATION,
            $owner,
        );

        $service->revokeApiKey($member, $team, $apiKey);

        $this->assertFalse($apiKey->fresh()->is_active);
    }

    // -------------------------------------------------------------------------
    // Test 5: TREASURY_OPERATIONS gate enforcement
    // -------------------------------------------------------------------------

    public function test_it_enforces_treasury_operations_capability(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $member = User::factory()->create();
        $team->users()->attach($member);
        $member->current_team_id = $team->id;
        $member->save();

        $service = app(CorporateTreasuryOperationsService::class);

        // Non-holder should throw
        $thrown = false;
        try {
            $service->authorizeAndRecordAllocation($member, $team, 'alloc-ref-001', 500000, 'ZAR');
        } catch (AuthorizationException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Expected AuthorizationException for member without TREASURY_OPERATIONS');

        // Grant capability and retry
        $team->resolveCorporateProfile()->grantCapabilityToUser(
            $member,
            CorporateCapability::TREASURY_OPERATIONS,
            $owner,
        );

        $result = $service->authorizeAndRecordAllocation($member, $team, 'alloc-ref-001', 500000, 'ZAR');

        $this->assertTrue($result['authorized']);
        $this->assertSame('alloc-ref-001', $result['reference']);
        $this->assertSame(500000, $result['amount']);
        $this->assertSame('ZAR', $result['asset']);
        $this->assertSame($member->id, $result['authorized_by']);
    }

    // -------------------------------------------------------------------------
    // Test 6: Personal teams skip corporate capability gates
    // -------------------------------------------------------------------------

    public function test_personal_team_is_not_subject_to_corporate_capability_gates(): void
    {
        $owner = User::factory()->create();

        // Personal team — is_business_organization = false
        $personalTeam = Team::factory()->create([
            'user_id'                  => $owner->id,
            'name'                     => 'Personal Team',
            'personal_team'            => true,
            'is_business_organization' => false,
        ]);
        $owner->current_team_id = $personalTeam->id;
        $owner->save();

        $randomMember = User::factory()->create();
        $personalTeam->users()->attach($randomMember);

        // CorporateMemberService: personal team, any user can call (no gate)
        $memberService = app(CorporateMemberService::class);
        $result = $memberService->assignRole($randomMember, $personalTeam, $randomMember, 'accountant');
        $this->assertSame('accountant', $result->role);

        // CorporateSpendControlService: personal team, no gate enforced
        $spendService = app(CorporateSpendControlService::class);
        $limit = $spendService->configureAgentSpendingLimit($randomMember, $personalTeam, 'personal-agent', 5000, 500);
        $this->assertSame(5000, $limit->daily_limit);

        // CorporateTreasuryOperationsService: personal team, no gate
        $treasuryService = app(CorporateTreasuryOperationsService::class);
        $res = $treasuryService->authorizeAndRecordAllocation($randomMember, $personalTeam, 'ref-personal', 1000, 'USD');
        $this->assertTrue($res['authorized']);

        // CorporateApiAdminService: personal team, no gate
        /** @var ApiKey $apiKey */
        $apiKey = ApiKey::create([
            'uuid'       => \Illuminate\Support\Str::uuid()->toString(),
            'user_uuid'  => $owner->uuid,
            'name'       => 'Personal Key',
            'key_prefix' => 'pers1234',
            'key_hash'   => 'dummy_hash_personal',
            'is_active'  => true,
        ]);

        $apiService = app(CorporateApiAdminService::class);
        $apiService->revokeApiKey($randomMember, $personalTeam, $apiKey);
        $this->assertFalse($apiKey->fresh()->is_active);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createBusinessTeamForOwner(User $owner): Team
    {
        $team = Team::factory()->create([
            'user_id'                      => $owner->id,
            'name'                         => 'Acme Corp',
            'personal_team'                => false,
            'is_business_organization'     => true,
            'organization_type'            => 'business',
            'business_registration_number' => 'REG-TEST-001',
            'tax_id'                       => 'TAX-TEST-001',
            'business_details'             => [
                'legal_name' => 'Acme Corp (Pty) Ltd',
                'country'    => 'ZA',
            ],
        ]);

        $owner->current_team_id = $team->id;
        $owner->save();

        return $team;
    }

    private function ensureCapabilityEnforcementSchemaBaseline(): void
    {
        if (! Schema::hasTable('mpp_spending_limits')) {
            Schema::create('mpp_spending_limits', function (Blueprint $table): void {
                $table->id();
                $table->string('agent_id')->index();
                $table->integer('daily_limit')->default(0);
                $table->integer('per_tx_limit')->default(0);
                $table->integer('spent_today')->default(0);
                $table->boolean('auto_pay')->default(false);
                $table->string('last_reset')->nullable();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('user_uuid')->nullable();
                $table->string('name');
                $table->string('key_prefix', 16)->nullable();
                $table->string('key_hash')->nullable();
                $table->string('description')->nullable();
                $table->json('permissions')->nullable();
                $table->json('rate_limits')->nullable();
                $table->json('allowed_ips')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->string('last_used_ip')->nullable();
                $table->integer('request_count')->default(0);
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function ensureCorporateSectionSchemaBaseline(): void
    {
        if (Schema::hasTable('teams')) {
            Schema::table('teams', function (Blueprint $table): void {
                if (! Schema::hasColumn('teams', 'is_business_organization')) {
                    $table->boolean('is_business_organization')->default(false)->after('personal_team');
                }

                if (! Schema::hasColumn('teams', 'organization_type')) {
                    $table->string('organization_type')->nullable()->after('is_business_organization');
                }

                if (! Schema::hasColumn('teams', 'business_registration_number')) {
                    $table->string('business_registration_number')->nullable()->after('organization_type');
                }

                if (! Schema::hasColumn('teams', 'tax_id')) {
                    $table->string('tax_id')->nullable()->after('business_registration_number');
                }

                if (! Schema::hasColumn('teams', 'business_details')) {
                    $table->json('business_details')->nullable()->after('tax_id');
                }

                if (! Schema::hasColumn('teams', 'max_users')) {
                    $table->integer('max_users')->default(5)->after('business_details');
                }

                if (! Schema::hasColumn('teams', 'allowed_roles')) {
                    $table->json('allowed_roles')->nullable()->after('max_users');
                }
            });
        }

        if (! Schema::hasTable('team_user_roles')) {
            Schema::create('team_user_roles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role');
                $table->json('permissions')->nullable();
                $table->timestamps();

                $table->unique(['team_id', 'user_id', 'role']);
                $table->index(['team_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('corporate_profiles')) {
            Schema::create('corporate_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('legal_name');
                $table->string('registration_number')->nullable();
                $table->string('tax_id')->nullable();
                $table->string('organization_type')->nullable();
                $table->string('kyb_status', 32)->default('not_started');
                $table->string('operating_status', 32)->default('pending');
                $table->string('contract_reference')->nullable();
                $table->string('pricing_reference')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('corporate_capability_grants')) {
            Schema::create('corporate_capability_grants', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('corporate_profile_id')->constrained('corporate_profiles')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('capability', 64);
                $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('approval_threshold_amount', 20, 2)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['corporate_profile_id', 'user_id', 'capability'], 'corp_profile_user_capability_unique');
            });
        }
    }
}
