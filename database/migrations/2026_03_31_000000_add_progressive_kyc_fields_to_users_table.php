<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_identity_type')->nullable()->after('kyc_level');
            $table->string('kyc_current_step')->default('identity_type')->after('kyc_identity_type');
            $table->json('kyc_steps_completed')->nullable()->after('kyc_current_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['kyc_identity_type', 'kyc_current_step', 'kyc_steps_completed']);
        });
    }
};
