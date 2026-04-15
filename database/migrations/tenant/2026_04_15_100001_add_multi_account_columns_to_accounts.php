<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('name');
            $table->string('legal_name')->nullable()->after('display_name');
            $table->string('verification_tier')->default('unverified')->after('status');
            $table->json('capabilities')->nullable()->after('metadata');
        });

        // Update existing rows: rename 'standard' type to 'personal'
        DB::table('accounts')
            ->where('type', 'standard')
            ->update(['type' => 'personal']);
    }

    public function down(): void
    {
        // Revert 'personal' back to 'standard'
        DB::table('accounts')
            ->where('type', 'personal')
            ->update(['type' => 'standard']);

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'legal_name', 'verification_tier', 'capabilities']);
        });
    }
};
