<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Commerce\Enums\MerchantStatus;
use App\Domain\Commerce\Services\MerchantOnboardingService;
use App\Domain\Corporate\Enums\CorporateCapability;
use App\Domain\Corporate\Services\CorporateCapabilityGate;
use App\GraphQL\Mutations\Commerce\ApproveMerchantMutation;
use App\GraphQL\Mutations\Commerce\SubmitMerchantApplicationMutation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CorporateProfileAndMerchantOnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureCorporateSectionSchemaBaseline();
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    public function test_it_creates_a_first_class_corporate_profile_over_a_business_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $profile = $team->resolveCorporateProfile();

        $this->assertSame($team->id, $profile->team_id);
        $this->assertSame('Acme Treasury (Pty) Ltd', $profile->legal_name);
        $this->assertSame('REG-123456', $profile->registration_number);
        $this->assertTrue($team->fresh()->corporateProfile?->is($profile));
    }

    public function test_it_persists_explicit_capability_grants_and_enforces_them_through_the_corporate_gate(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);
        $profile = $team->resolveCorporateProfile();

        $member = User::factory()->create();
        $team->users()->attach($member);
        $member->current_team_id = $team->id;
        $member->save();
        $team->assignUserRole($member, 'compliance_officer');

        $profile->grantCapabilityToUser(
            $member,
            CorporateCapability::COMPLIANCE_REVIEW,
            $owner,
        );

        $gate = app(CorporateCapabilityGate::class);

        $this->assertTrue($gate->allows($member, $team, CorporateCapability::COMPLIANCE_REVIEW));
        $this->assertFalse($gate->allows($member, $team, CorporateCapability::TREASURY_OPERATIONS));
    }

    public function test_it_persists_merchant_onboarding_in_the_database_and_no_longer_depends_on_in_memory_service_state(): void
    {
        $owner = User::factory()->create();
        $this->createBusinessTeamForOwner($owner);

        $this->actingAs($owner);

        $merchant = app(SubmitMerchantApplicationMutation::class)(
            null,
            [
                'display_name' => 'Acme Merchant',
                'icon_url' => 'https://example.com/icon.png',
                'accepted_assets' => ['USDC'],
                'accepted_networks' => ['POLYGON'],
                'terminal_id' => 'term_123',
            ],
        );

        $freshService = app(MerchantOnboardingService::class);
        $freshService->startReview($merchant->id, (string) $owner->id);
        $freshService->approve($merchant->id, (string) $owner->id);

        $merchant->refresh();
        $case = $merchant->businessOnboardingCase()->firstOrFail();

        $this->assertNotNull($merchant->corporate_profile_id);
        $this->assertNotNull($merchant->business_onboarding_case_id);
        $this->assertSame(MerchantStatus::APPROVED, $merchant->status);
        $this->assertSame(MerchantStatus::APPROVED->value, $case->status);
        $this->assertCount(3, $case->statusHistory);
    }

    public function test_it_requires_an_explicit_corporate_compliance_capability_before_approving_a_merchant(): void
    {
        $owner = User::factory()->create();
        $team = $this->createBusinessTeamForOwner($owner);

        $submitter = User::factory()->create();
        $team->users()->attach($submitter);
        $submitter->current_team_id = $team->id;
        $submitter->save();

        $reviewer = User::factory()->create();
        $team->users()->attach($reviewer);
        $reviewer->current_team_id = $team->id;
        $reviewer->save();
        $team->assignUserRole($reviewer, 'compliance_officer');

        $this->actingAs($submitter);
        $merchant = app(SubmitMerchantApplicationMutation::class)(
            null,
            [
                'display_name' => 'Capability Merchant',
                'accepted_assets' => ['USDC'],
                'accepted_networks' => ['POLYGON'],
            ],
        );

        app(MerchantOnboardingService::class)->startReview($merchant->id, (string) $owner->id);

        $this->actingAs($reviewer);

        $thrown = false;

        try {
            app(ApproveMerchantMutation::class)(null, ['id' => $merchant->id]);
        } catch (AuthorizationException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);

        $team->resolveCorporateProfile()->grantCapabilityToUser(
            $reviewer,
            CorporateCapability::COMPLIANCE_REVIEW,
            $owner,
        );

        $approvedMerchant = app(ApproveMerchantMutation::class)(null, ['id' => $merchant->id]);

        $this->assertSame(MerchantStatus::APPROVED, $approvedMerchant->fresh()->status);
    }

    private function createBusinessTeamForOwner(User $owner): Team
    {
        $team = Team::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Acme Treasury',
            'personal_team' => false,
            'is_business_organization' => true,
            'organization_type' => 'business',
            'business_registration_number' => 'REG-123456',
            'tax_id' => 'TAX-987654',
            'business_details' => [
                'legal_name' => 'Acme Treasury (Pty) Ltd',
                'country' => 'SZ',
            ],
        ]);

        $owner->current_team_id = $team->id;
        $owner->save();

        return $team;
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
