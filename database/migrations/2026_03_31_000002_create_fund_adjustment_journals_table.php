<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fund_adjustment_journals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->uuid('account_uuid');
            $table->uuid('user_uuid');
            $table->string('asset_code', 10);
            $table->bigInteger('adjustment_amount');
            $table->enum('adjustment_type', ['credit', 'debit']);
            $table->string('reason_category', 50);
            $table->text('description');
            $table->string('supporting_document')->nullable();
            $table->string('performed_by', 255);
            $table->string('approved_by', 255)->nullable();
            $table->timestamp('performed_at');
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'reversed'])->default('pending');
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
            $table->index('reason_category');
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_adjustment_journals');
    }
};
