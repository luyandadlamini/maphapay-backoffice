<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The database connection that should be used by the migration.
     */
    public function getConnection(): string
    {
        return 'central';
    }

    public function up(): void
    {
        Schema::connection('central')->table('account_memberships', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('account_type');
            $table->string('verification_tier')->default('unverified')->after('display_name');
            $table->json('capabilities')->nullable()->after('verification_tier');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('account_memberships', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'verification_tier', 'capabilities']);
        });
    }
};
