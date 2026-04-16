<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('tier')->nullable()->after('type'); // 'grow' for 6-12, 'rise' for 13-17
            $table->integer('permission_level')->nullable()->after('tier'); // 1-6
            $table->uuid('parent_account_id')->nullable()->after('user_uuid')->index(); // Link to parent account
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['tier', 'permission_level', 'parent_account_id']);
        });
    }
};
