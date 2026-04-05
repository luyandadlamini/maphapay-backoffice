<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (app()->isProduction()) {
            return;
        }

        Schema::create('test_fundings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->uuid('account_uuid');
            $table->uuid('user_uuid');
            $table->string('asset_code', 10);
            $table->bigInteger('amount');
            $table->float('amount_formatted')->nullable();
            $table->string('reason', 50);
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('performed_by', 255);
            $table->timestamp('performed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->cascadeOnDelete();
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('asset_code')
                ->references('code')
                ->on('assets')
                ->cascadeOnDelete();

            $table->index('account_uuid');
            $table->index('user_uuid');
            $table->index('status');
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        if (app()->isProduction()) {
            return;
        }

        Schema::dropIfExists('test_fundings');
    }
};
