<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('minor_account_lifecycle_exception_acknowledgments')) {
            return;
        }

        Schema::create('minor_account_lifecycle_exception_acknowledgments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('minor_account_lifecycle_exception_id');
            $table->uuid('acknowledged_by_user_uuid');
            $table->text('note');
            $table->timestamp('created_at')->useCurrent();

            $table->index('minor_account_lifecycle_exception_id', 'minor_lifecycle_exception_ack_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_account_lifecycle_exception_acknowledgments');
    }
};
