<?php

declare(strict_types=1);

namespace Tests\Feature\Corporate;

use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Corporate\Models\CorporateTreasuryAccount;
use App\Domain\Corporate\Services\CorporateTreasuryBoundary;
use App\Domain\MachinePay\Models\MppSpendingLimit;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CorporateTreasuryBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureCorporateSectionSchemaBaseline();
        $this->ensureTreasuryBoundarySchemaBaseline();
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    public function test_it_registers_treasury_account_for_corporate_profile(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporateTreasuryBoundary::class);

        $account = $service->registerTreasuryAccount(
            $profile,
            'acct-treasury-uuid-001',
            'USD',
            'Main USD Treasury',
        );

        $this->assertInstanceOf(CorporateTreasuryAccount::class, $account);
        $this->assertSame($profile->id, $account->corporate_profile_id);
        $this->assertSame('acct-treasury-uuid-001', $account->treasury_account_id);
        $this->assertSame('treasury', $account->account_type);
        $this->assertSame('USD', $account->asset_code);
        $this->assertSame('Main USD Treasury', $account->label);
        $this->assertTrue($account->is_active);

        $this->assertDatabaseHas('corporate_treasury_accounts', [
            'corporate_profile_id' => $profile->id,
            'treasury_account_id'  => 'acct-treasury-uuid-001',
            'account_type'         => 'treasury',
        ]);
    }

    public function test_it_registers_spend_account_for_corporate_profile(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporateTreasuryBoundary::class);

        $account = $service->registerSpendAccount(
            $profile,
            'acct-spend-uuid-001',
            'USDC',
            'USDC Spend Account',
        );

        $this->assertInstanceOf(CorporateTreasuryAccount::class, $account);
        $this->assertSame($profile->id, $account->corporate_profile_id);
        $this->assertSame('acct-spend-uuid-001', $account->treasury_account_id);
        $this->assertSame('spend', $account->account_type);
        $this->assertSame('USDC', $account->asset_code);
        $this->assertSame('USDC Spend Account', $account->label);
        $this->assertTrue($account->is_active);

        $this->assertDatabaseHas('corporate_treasury_accounts', [
            'corporate_profile_id' => $profile->id,
            'treasury_account_id'  => 'acct-spend-uuid-001',
            'account_type'         => 'spend',
        ]);
    }

    public function test_it_resolves_the_primary_treasury_account(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporateTreasuryBoundary::class);

        $service->registerTreasuryAccount($profile, 'acct-treasury-uuid-002', 'USD', 'Main Treasury');
        $service->registerSpendAccount($profile, 'acct-spend-uuid-002', 'USD', 'Spend Account');

        $resolved = $service->resolveTreasuryAccount($profile);

        $this->assertNotNull($resolved);
        $this->assertSame('treasury', $resolved->account_type);
        $this->assertSame('acct-treasury-uuid-002', $resolved->treasury_account_id);
    }

    public function test_it_returns_null_when_no_treasury_account_registered(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporateTreasuryBoundary::class);

        $resolved = $service->resolveTreasuryAccount($profile);

        $this->assertNull($resolved);
    }

    public function test_it_resolves_all_spend_accounts(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporateTreasuryBoundary::class);

        $service->registerSpendAccount($profile, 'acct-spend-uuid-003', 'USD', 'USD Spend');
        $service->registerSpendAccount($profile, 'acct-spend-uuid-004', 'USDC', 'USDC Spend');
        $service->registerTreasuryAccount($profile, 'acct-treasury-uuid-003', 'USD', 'Main Treasury');

        $spendAccounts = $service->resolveSpendAccounts($profile);

        $this->assertCount(2, $spendAccounts);

        $accountIds = array_map(fn (CorporateTreasuryAccount $a) => $a->treasury_account_id, $spendAccounts);
        $this->assertContains('acct-spend-uuid-003', $accountIds);
        $this->assertContains('acct-spend-uuid-004', $accountIds);
    }

    public function test_it_anchors_mpp_spending_limit_to_corporate_profile(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporateTreasuryBoundary::class);

        /** @var MppSpendingLimit $limit */
        $limit = MppSpendingLimit::create([
            'agent_id'   => 'agent-test-001',
            'daily_limit'  => 100000,
            'per_tx_limit' => 10000,
            'spent_today'  => 0,
            'auto_pay'     => false,
            'last_reset'   => now()->toDateString(),
            'team_id'      => null,
        ]);

        $this->assertNull($limit->team_id);

        $updated = $service->anchorMppSpendingLimit($profile, $limit);

        $this->assertInstanceOf(MppSpendingLimit::class, $updated);
        $this->assertSame($profile->team_id, $updated->team_id);

        $this->assertDatabaseHas('mpp_spending_limits', [
            'id'      => $limit->id,
            'team_id' => $profile->team_id,
        ]);
    }

    public function test_it_identifies_treasury_account_by_id(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporateTreasuryBoundary::class);

        $service->registerTreasuryAccount($profile, 'acct-treasury-uuid-005', 'USD', 'Main Treasury');
        $service->registerSpendAccount($profile, 'acct-spend-uuid-005', 'USD', 'Spend');

        $this->assertTrue($service->isTreasuryAccount($profile, 'acct-treasury-uuid-005'));
        $this->assertFalse($service->isTreasuryAccount($profile, 'acct-spend-uuid-005'));
        $this->assertFalse($service->isTreasuryAccount($profile, 'unknown-acct-id'));
    }

    public function test_idempotent_registration_does_not_create_duplicates(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporateTreasuryBoundary::class);

        $first  = $service->registerTreasuryAccount($profile, 'acct-treasury-uuid-006', 'USD', 'First Label');
        $second = $service->registerTreasuryAccount($profile, 'acct-treasury-uuid-006', 'USD', 'Updated Label');

        $this->assertSame($first->id, $second->id);

        $count = CorporateTreasuryAccount::query()
            ->where('corporate_profile_id', $profile->id)
            ->where('treasury_account_id', 'acct-treasury-uuid-006')
            ->count();

        $this->assertSame(1, $count);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function makeCorporateProfile(): CorporateProfile
    {
        $owner = User::factory()->create();

        $team = Team::factory()->create([
            'user_id'                    => $owner->id,
            'name'                       => 'Test Corporate Team',
            'personal_team'              => false,
            'is_business_organization'   => true,
            'organization_type'          => 'business',
            'business_registration_number' => 'REG-TEST-001',
            'tax_id'                     => 'TAX-TEST-001',
            'business_details'           => ['legal_name' => 'Test Corp (Pty) Ltd', 'country' => 'SZ'],
        ]);

        $owner->current_team_id = $team->id;
        $owner->save();

        return $team->resolveCorporateProfile();
    }

    // ---------------------------------------------------------------------------
    // Schema baseline helpers
    // ---------------------------------------------------------------------------

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

    private function ensureTreasuryBoundarySchemaBaseline(): void
    {
        if (! Schema::hasTable('corporate_treasury_accounts')) {
            Schema::create('corporate_treasury_accounts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('corporate_profile_id')->constrained('corporate_profiles')->cascadeOnDelete();
                $table->string('treasury_account_id', 64);
                $table->string('account_type', 32);
                $table->string('asset_code', 16)->nullable();
                $table->string('label')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['corporate_profile_id', 'treasury_account_id'], 'corp_treasury_acct_unique');
                $table->index(['corporate_profile_id', 'account_type'], 'corp_treasury_acct_type_idx');
            });
        }

        if (! Schema::hasTable('mpp_spending_limits')) {
            Schema::create('mpp_spending_limits', function (Blueprint $table): void {
                $table->id();
                $table->string('agent_id');
                $table->integer('daily_limit')->default(0);
                $table->integer('per_tx_limit')->default(0);
                $table->integer('spent_today')->default(0);
                $table->boolean('auto_pay')->default(false);
                $table->string('last_reset');
                $table->integer('team_id')->nullable();
                $table->timestamps();
            });
        }
    }
}
