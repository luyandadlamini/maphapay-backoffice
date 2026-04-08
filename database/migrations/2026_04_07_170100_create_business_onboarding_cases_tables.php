<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
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

        Schema::table('merchants', function (Blueprint $table): void {
            $table->foreignUuid('corporate_profile_id')->nullable()->after('terminal_id')->constrained('corporate_profiles')->nullOnDelete();
            $table->foreignUuid('business_onboarding_case_id')->nullable()->after('corporate_profile_id')->constrained('business_onboarding_cases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_onboarding_case_id');
            $table->dropConstrainedForeignId('corporate_profile_id');
        });

        Schema::dropIfExists('business_onboarding_case_status_history');
        Schema::dropIfExists('business_onboarding_cases');
    }
};
