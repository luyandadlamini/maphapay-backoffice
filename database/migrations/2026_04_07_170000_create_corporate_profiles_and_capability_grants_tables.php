<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('corporate_capability_grants');
        Schema::dropIfExists('corporate_profiles');
    }
};
