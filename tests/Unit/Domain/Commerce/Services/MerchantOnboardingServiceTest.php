<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Commerce\Services;

use App\Domain\Commerce\Enums\MerchantStatus;
use App\Domain\Commerce\Events\MerchantOnboarded;
use App\Domain\Commerce\Services\MerchantOnboardingService;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class MerchantOnboardingServiceTest extends TestCase
{
    protected MerchantOnboardingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureCorporateSectionSchemaBaseline();

        Event::fake();
        $this->service = app(MerchantOnboardingService::class);
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    public function test_submit_application_creates_a_persisted_merchant_application(): void
    {
        $owner = $this->actingAsBusinessOwner();

        $result = $this->service->submitApplication(
            businessName: 'Test Shop',
            businessType: 'retail',
            country: 'US',
            contactEmail: 'test@shop.com',
        );

        $merchant = $this->service->getMerchant($result['merchant_id']);

        $this->assertNotEmpty($result['merchant_id']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame('Test Shop', $merchant['business_name']);
        $this->assertSame((int) $owner->current_team_id, Team::find($owner->current_team_id)?->id);
    }

    public function test_submit_application_persists_business_details(): void
    {
        $this->actingAsBusinessOwner();

        $result = $this->service->submitApplication(
            businessName: 'Test Shop',
            businessType: 'retail',
            country: 'US',
            contactEmail: 'test@shop.com',
            businessDetails: ['registration_number' => '123456'],
        );

        $merchant = $this->service->getMerchant($result['merchant_id']);

        $this->assertSame('123456', $merchant['business_details']['registration_number']);
    }

    public function test_it_transitions_through_the_persisted_onboarding_flow(): void
    {
        $owner = $this->actingAsBusinessOwner();

        $result = $this->service->submitApplication(
            businessName: 'Test Shop',
            businessType: 'retail',
            country: 'US',
            contactEmail: 'test@shop.com',
        );

        $merchantId = $result['merchant_id'];

        $this->service->startReview($merchantId, (string) $owner->id);
        $this->assertSame(MerchantStatus::UNDER_REVIEW, $this->service->getMerchantStatus($merchantId));

        $this->service->approve($merchantId, (string) $owner->id);
        $this->assertSame(MerchantStatus::APPROVED, $this->service->getMerchantStatus($merchantId));

        $this->service->activate($merchantId);
        $this->assertSame(MerchantStatus::ACTIVE, $this->service->getMerchantStatus($merchantId));

        Event::assertDispatched(MerchantOnboarded::class, function (MerchantOnboarded $event) use ($merchantId): bool {
            return $event->merchantId === $merchantId
                && $event->status === MerchantStatus::ACTIVE;
        });
    }

    public function test_it_allows_suspension_and_reactivation(): void
    {
        $owner = $this->actingAsBusinessOwner();

        $result = $this->service->submitApplication(
            businessName: 'Test Shop',
            businessType: 'retail',
            country: 'US',
            contactEmail: 'test@shop.com',
        );

        $merchantId = $result['merchant_id'];

        $this->service->startReview($merchantId, (string) $owner->id);
        $this->service->approve($merchantId, (string) $owner->id);
        $this->service->activate($merchantId);

        $this->service->suspend($merchantId, 'Policy violation');
        $this->assertSame(MerchantStatus::SUSPENDED, $this->service->getMerchantStatus($merchantId));
        $this->assertFalse($this->service->canAcceptPayments($merchantId));

        $this->service->reactivate($merchantId, 'Issue resolved');
        $this->assertSame(MerchantStatus::ACTIVE, $this->service->getMerchantStatus($merchantId));
        $this->assertTrue($this->service->canAcceptPayments($merchantId));
    }

    public function test_it_throws_on_invalid_transition(): void
    {
        $this->actingAsBusinessOwner();

        $result = $this->service->submitApplication(
            businessName: 'Test Shop',
            businessType: 'retail',
            country: 'US',
            contactEmail: 'test@shop.com',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot transition');

        $this->service->activate($result['merchant_id']);
    }

    public function test_it_tracks_status_history_and_assesses_risk(): void
    {
        $owner = $this->actingAsBusinessOwner();

        $result = $this->service->submitApplication(
            businessName: 'Crypto Exchange',
            businessType: 'crypto',
            country: 'KP',
            contactEmail: 'test@crypto.com',
        );

        $merchantId = $result['merchant_id'];

        $this->service->startReview($merchantId, (string) $owner->id);
        $this->service->approve($merchantId, (string) $owner->id);

        $history = $this->service->getStatusHistory($merchantId);
        $assessment = $this->service->assessRisk($merchantId);

        $this->assertCount(3, $history);
        $this->assertSame('pending', $history[0]['status']);
        $this->assertSame('under_review', $history[1]['status']);
        $this->assertSame('approved', $history[2]['status']);
        $this->assertGreaterThanOrEqual(0.7, $assessment['risk_score']);
        $this->assertContains('High-risk business category', $assessment['risk_factors']);
        $this->assertContains('High-risk jurisdiction', $assessment['risk_factors']);
        $this->assertSame('reject', $assessment['recommendation']);
    }

    public function test_get_merchant_throws_for_non_existent_merchant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Merchant not found');

        $this->service->getMerchant('non-existent');
    }

    private function actingAsBusinessOwner(): User
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create([
            'user_id'                      => $owner->id,
            'name'                         => 'Acme Treasury',
            'personal_team'                => false,
            'is_business_organization'     => true,
            'organization_type'            => 'business',
            'business_registration_number' => 'REG-123456',
            'tax_id'                       => 'TAX-987654',
            'business_details'             => [
                'legal_name' => 'Acme Treasury (Pty) Ltd',
                'country'    => 'SZ',
            ],
        ]);

        $owner->current_team_id = $team->id;
        $owner->save();

        $this->actingAs($owner);

        return $owner;
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
