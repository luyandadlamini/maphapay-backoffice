<?php

declare(strict_types=1);

namespace Tests\Feature\Corporate;

use App\Domain\Corporate\Models\CorporateActionApprovalRequest;
use App\Domain\Corporate\Models\CorporatePayoutBatch;
use App\Domain\Corporate\Models\CorporatePayoutBatchItem;
use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Corporate\Services\CorporatePayoutBatchService;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class CorporatePayoutBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCorporateSectionSchemaBaseline();
        $this->ensurePayoutBatchSchemaBaseline();
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Test 1: Create a draft batch
    // -------------------------------------------------------------------------

    public function test_it_creates_a_draft_payout_batch(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporatePayoutBatchService::class);

        $batch = $service->createBatch($profile, 'ZAR', 'April Payroll');

        $this->assertInstanceOf(CorporatePayoutBatch::class, $batch);
        $this->assertSame('draft', $batch->status);
        $this->assertSame('ZAR', $batch->asset_code);
        $this->assertSame('April Payroll', $batch->label);
        $this->assertSame(0, $batch->total_amount_minor);
        $this->assertStringStartsWith('batch_', $batch->public_id);
        $this->assertSame($profile->id, $batch->corporate_profile_id);

        $this->assertDatabaseHas('corporate_payout_batches', [
            'id'                   => $batch->id,
            'status'               => 'draft',
            'asset_code'           => 'ZAR',
            'corporate_profile_id' => $profile->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 2: Add items to draft batch
    // -------------------------------------------------------------------------

    public function test_it_adds_items_to_a_draft_batch_and_updates_total(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporatePayoutBatchService::class);

        $batch = $service->createBatch($profile, 'ZAR');

        $item1 = $service->addItem($batch, 'user-001@example.com', 10000, 'ZAR', 'ref-001');
        $item2 = $service->addItem($batch, 'user-002@example.com', 25000, 'ZAR', 'ref-002');

        $this->assertInstanceOf(CorporatePayoutBatchItem::class, $item1);
        $this->assertSame('user-001@example.com', $item1->beneficiary_identifier);
        $this->assertSame(10000, $item1->amount_minor);
        $this->assertSame('ref-001', $item1->reference);
        $this->assertSame('pending', $item1->status);

        $fresh = $batch->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(35000, $fresh->total_amount_minor);
        $this->assertSame(2, $fresh->items()->count());
    }

    // -------------------------------------------------------------------------
    // Test 3: Reject blank beneficiary
    // -------------------------------------------------------------------------

    public function test_it_rejects_blank_beneficiary_identifier(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporatePayoutBatchService::class);

        $batch = $service->createBatch($profile, 'ZAR');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/beneficiary/i');

        $service->addItem($batch, '   ', 10000, 'ZAR', 'ref-blank');
    }

    // -------------------------------------------------------------------------
    // Test 4: Reject duplicate reference within same batch
    // -------------------------------------------------------------------------

    public function test_it_rejects_duplicate_references_within_the_same_batch(): void
    {
        $profile = $this->makeCorporateProfile();
        $service = app(CorporatePayoutBatchService::class);

        $batch = $service->createBatch($profile, 'ZAR');
        $service->addItem($batch, 'user-001@example.com', 10000, 'ZAR', 'ref-dupe');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');

        $service->addItem($batch, 'user-002@example.com', 5000, 'ZAR', 'ref-dupe');
    }

    // -------------------------------------------------------------------------
    // Test 5: Submit for approval wires to CorporateActionPolicy
    // -------------------------------------------------------------------------

    public function test_it_submits_batch_for_approval_and_persists_approval_request(): void
    {
        $owner = User::factory()->create();
        $profile = $this->makeCorporateProfileForOwner($owner);
        $service = app(CorporatePayoutBatchService::class);

        $batch = $service->createBatch($profile, 'ZAR', 'May Payroll');
        $service->addItem($batch, 'employee-a@co.com', 50000, 'ZAR', 'pay-001');
        $service->addItem($batch, 'employee-b@co.com', 60000, 'ZAR', 'pay-002');

        $this->actingAs($owner);
        $submitted = $service->submitForApproval($batch, $owner);

        $fresh = $submitted->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('submitted', $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertSame($owner->id, $fresh->submitted_by_id);
        $this->assertNotNull($fresh->approval_request_id);

        // Verify the approval request was persisted with correct metadata
        $approvalRequest = CorporateActionApprovalRequest::query()
            ->where('id', $fresh->approval_request_id)
            ->first();

        $this->assertNotNull($approvalRequest);
        $this->assertSame('treasury_affecting', $approvalRequest->action_type);
        $this->assertSame('pending', $approvalRequest->action_status);
        $this->assertSame('payout_batch', $approvalRequest->target_type);
        $this->assertSame($batch->public_id, $approvalRequest->target_identifier);
        $this->assertSame($owner->id, $approvalRequest->requester_id);
        $this->assertNull($approvalRequest->reviewer_id);

        // Evidence must carry batch metadata
        $evidence = $approvalRequest->evidence;
        $this->assertIsArray($evidence);
        $this->assertSame(2, $evidence['item_count']);
        $this->assertSame(110000, $evidence['total_amount_minor']);

        // Batch must NOT have executed
        $this->assertNull($fresh->executed_at);
        $this->assertNull($fresh->approved_at);
    }

    // -------------------------------------------------------------------------
    // Test 6: Cannot submit empty batch
    // -------------------------------------------------------------------------

    public function test_it_rejects_submission_of_empty_batch(): void
    {
        $owner = User::factory()->create();
        $profile = $this->makeCorporateProfileForOwner($owner);
        $service = app(CorporatePayoutBatchService::class);

        $batch = $service->createBatch($profile, 'ZAR');

        $this->expectException(InvalidArgumentException::class);
        $service->submitForApproval($batch, $owner);
    }

    // -------------------------------------------------------------------------
    // Test 7: Cannot add items to non-draft batch
    // -------------------------------------------------------------------------

    public function test_it_rejects_adding_items_to_a_non_draft_batch(): void
    {
        $owner = User::factory()->create();
        $profile = $this->makeCorporateProfileForOwner($owner);
        $service = app(CorporatePayoutBatchService::class);

        $batch = $service->createBatch($profile, 'ZAR');
        $service->addItem($batch, 'user@co.com', 10000, 'ZAR', 'pay-000');
        $service->submitForApproval($batch, $owner);

        // Now in 'submitted' state — adding items must throw
        $this->expectException(InvalidArgumentException::class);
        $service->addItem($batch, 'user2@co.com', 5000, 'ZAR', 'pay-999');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCorporateProfile(): CorporateProfile
    {
        $owner = User::factory()->create();

        return $this->makeCorporateProfileForOwner($owner);
    }

    private function makeCorporateProfileForOwner(User $owner): CorporateProfile
    {
        $team = Team::factory()->create([
            'user_id'                      => $owner->id,
            'name'                         => 'Payout Test Corp',
            'personal_team'                => false,
            'is_business_organization'     => true,
            'organization_type'            => 'business',
            'business_registration_number' => 'REG-PAYOUT-001',
            'tax_id'                       => 'TAX-PAYOUT-001',
            'business_details'             => ['legal_name' => 'Payout Corp (Pty) Ltd', 'country' => 'ZA'],
        ]);
        $owner->current_team_id = $team->id;
        $owner->save();

        return $team->resolveCorporateProfile();
    }

    // -------------------------------------------------------------------------
    // Schema baseline helpers
    // -------------------------------------------------------------------------

    private function ensurePayoutBatchSchemaBaseline(): void
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

        if (! Schema::hasTable('corporate_payout_batches')) {
            Schema::create('corporate_payout_batches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('public_id', 64)->unique();
                $table->foreignUuid('corporate_profile_id')->constrained('corporate_profiles')->cascadeOnDelete();
                $table->string('status', 32)->default('draft');
                $table->bigInteger('total_amount_minor')->default(0);
                $table->string('asset_code', 16);
                $table->string('label')->nullable();
                $table->timestamp('cut_off_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUuid('approval_request_id')->nullable()
                    ->constrained('corporate_action_approval_requests')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('corporate_payout_batch_items')) {
            Schema::create('corporate_payout_batch_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('batch_id')->constrained('corporate_payout_batches')->cascadeOnDelete();
                $table->string('beneficiary_identifier', 255);
                $table->bigInteger('amount_minor');
                $table->string('asset_code', 16);
                $table->string('reference', 128);
                $table->string('status', 32)->default('pending');
                $table->text('error_reason')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['batch_id', 'reference'], 'corp_payout_item_batch_ref_unique');
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
