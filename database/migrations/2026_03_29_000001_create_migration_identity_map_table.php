<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('migration_identity_map', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_user_id')->unique();
            $table->uuid('finaegis_user_uuid');
            $table->timestamp('migrated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_identity_map');
    }
};
