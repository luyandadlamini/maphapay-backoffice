<?php

declare(strict_types=1);

namespace Tests\Feature\Corporate;

use App\Domain\Corporate\Models\CorporateActionApprovalRequest;
use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Corporate\Services\CorporateActionPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CorporateActionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureCorporateSectionSchemaBaseline();
        $this->ensurePolicySchemaBaseline();
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    public function test_it_classifies_treasury_affecting_actions_as_request_approve(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $policy = app(CorporateActionPolicy::class);

        $result = $policy->classify('treasury_affecting', $owner, $team);

        $this->assertSame('request_approve', $result);
    }

    public function test_it_classifies_membership_changes_as_request_approve(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $policy = app(CorporateActionPolicy::class);

        $result = $policy->classify('membership_change', $owner, $team);

        $this->assertSame('request_approve', $result);
    }

    public function test_it_classifies_api_ownership_changes_as_direct_elevated(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $policy = app(CorporateActionPolicy::class);

        $result = $policy->classify('api_ownership_change', $owner, $team);

        $this->assertSame('direct_elevated', $result);
    }

    public function test_it_classifies_unknown_action_types_as_blocked(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $policy = app(CorporateActionPolicy::class);

        $this->assertSame('blocked', $policy->classify('unknown_action', $owner, $team));
        $this->assertSame('blocked', $policy->classify('', $owner, $team));
        $this->assertSame('blocked', $policy->classify('random_thing', $owner, $team));
    }

    public function test_it_persists_approval_request_with_correct_metadata(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $policy = app(CorporateActionPolicy::class);

        $evidence = [
            ['type' => 'document', 'reference' => 'doc-abc123'],
            ['type' => 'note', 'text' => 'Approved by CFO verbally'],
        ];

        $request = $policy->submitApprovalRequest(
            requester: $owner,
            team: $team,
            actionType: 'treasury_affecting',
            targetType: 'treasury_account',
            targetIdentifier: 'acct-uuid-0001',
            evidence: $evidence,
        );

        $this->assertInstanceOf(CorporateActionApprovalRequest::class, $request);
        $this->assertNotNull($request->id);

        // Verify DB record
        $this->assertDatabaseHas('corporate_action_approval_requests', [
            'id'                => $request->id,
            'action_type'       => 'treasury_affecting',
            'action_status'     => 'pending',
            'requester_id'      => $owner->id,
            'target_type'       => 'treasury_account',
            'target_identifier' => 'acct-uuid-0001',
            'reviewer_id'       => null,
        ]);

        // Verify the corporate_profile_id is linked correctly
        $this->assertNotNull($request->corporate_profile_id);

        // Verify evidence is persisted
        $fresh = $request->fresh();
        $this->assertNotNull($fresh);
        $this->assertIsArray($fresh->evidence);
        $this->assertCount(2, $fresh->evidence);
        $this->assertSame('document', $fresh->evidence[0]['type']);

        // reviewer_id must be null until approved/rejected
        $this->assertNull($fresh->reviewer_id);
    }

    public function test_it_approves_request_and_records_reviewer(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $policy = app(CorporateActionPolicy::class);

        $request = $policy->submitApprovalRequest(
            requester: $owner,
            team: $team,
            actionType: 'membership_change',
            targetType: 'team_member',
            targetIdentifier: (string) $reviewer->id,
        );

        $this->assertSame('pending', $request->action_status);

        $policy->approve($request, $reviewer, 'Looks good to me.');

        $fresh = $request->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('approved', $fresh->action_status);
        $this->assertSame($reviewer->id, $fresh->reviewer_id);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame('Looks good to me.', $fresh->review_reason);

        // Self-approval must be rejected
        $request2 = $policy->submitApprovalRequest(
            requester: $owner,
            team: $team,
            actionType: 'treasury_affecting',
            targetType: 'treasury_account',
            targetIdentifier: 'acct-uuid-0002',
        );

        $this->expectException(\InvalidArgumentException::class);
        $policy->approve($request2, $owner, 'I approve my own request');
    }

    public function test_it_rejects_request_and_records_reason(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $policy = app(CorporateActionPolicy::class);

        $request = $policy->submitApprovalRequest(
            requester: $owner,
            team: $team,
            actionType: 'treasury_affecting',
            targetType: 'treasury_account',
            targetIdentifier: 'acct-uuid-0003',
        );

        $policy->reject($request, $reviewer, 'Insufficient documentation provided.');

        $fresh = $request->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('rejected', $fresh->action_status);
        $this->assertSame($reviewer->id, $fresh->reviewer_id);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame('Insufficient documentation provided.', $fresh->review_reason);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createBusinessTeamForOwner(User $owner): Team
    {
        $team = Team::factory()->create([
            'user_id'                     => $owner->id,
            'name'                        => 'Policy Test Corp',
            'personal_team'               => false,
            'is_business_organization'    => true,
            'organization_type'           => 'business',
            'business_registration_number' => 'REG-POL-001',
            'tax_id'                      => 'TAX-POL-001',
            'business_details'            => [
                'legal_name' => 'Policy Test Corp (Pty) Ltd',
                'country'    => 'SZ',
            ],
        ]);

        $owner->current_team_id = $team->id;
        $owner->save();

        return $team;
    }

    // -------------------------------------------------------------------------
    // Schema bootstrap helpers
    // -------------------------------------------------------------------------

    private function ensurePolicySchemaBaseline(): void
    {
        if (! Schema::hasTable('corporate_action_approval_requests')) {
            Schema::create('corporate_action_approval_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('corporate_profile_id')->constrained('corporate_profiles')->cascadeOnDelete();
                $table->string('action_type', 64);
                $table->string('action_status', 32)->default('pending');
                $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('target_type', 64);
                $table->string('target_identifier', 255);
                $table->json('evidence')->nullable();
                $table->json('action_metadata')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_reason')->nullable();
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

        if (! Schema::hasTable('merchants')) {
            Schema::create('merchants', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('public_id', 64)->unique();
                $table->string('display_name');
                $table->string('icon_url')->nullable();
                $table->json('accepted_assets');
                $table->json('accepted_networks');
                $table->string('status', 20)->default('pending');
                $table->string('terminal_id', 64)->nullable()->index();
                $table->foreignUuid('corporate_profile_id')->nullable()->constrained('corporate_profiles')->nullOnDelete();
                $table->uuid('business_onboarding_case_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        } else {
            Schema::table('merchants', function (Blueprint $table): void {
                if (! Schema::hasColumn('merchants', 'corporate_profile_id')) {
                    $table->foreignUuid('corporate_profile_id')->nullable()->after('terminal_id')->constrained('corporate_profiles')->nullOnDelete();
                }

                if (! Schema::hasColumn('merchants', 'business_onboarding_case_id')) {
                    $table->uuid('business_onboarding_case_id')->nullable()->after('corporate_profile_id');
                }
            });
        }

        if (! Schema::hasTable('business_onboarding_cases')) {
            Schema::create('business_onboarding_cases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('public_id', 64)->unique();
                $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignUuid('corporate_profile_id')->nullable()->constrained('corporate_profiles')->nullOnDelete();
                $table->foreignUuid('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
                $table->string('relationship_type', 32);
                $table->string('status', 32);
                $table->string('business_name');
                $table->string('business_type', 64)->nullable();
                $table->string('country', 8)->nullable();
                $table->string('contact_email')->nullable();
                $table->json('requested_capabilities')->nullable();
                $table->json('business_details')->nullable();
                $table->json('evidence')->nullable();
                $table->json('risk_assessment')->nullable();
                $table->json('activation_requirements')->nullable();
                $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('last_decision_reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('business_onboarding_case_status_history')) {
            Schema::create('business_onboarding_case_status_history', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('business_onboarding_case_id')
                    ->constrained('business_onboarding_cases', indexName: 'biz_onboard_case_status_fk')
                    ->cascadeOnDelete();
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }
}
