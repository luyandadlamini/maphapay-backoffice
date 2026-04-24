<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('guardian_invites')) {
            return;
        }

        Schema::connection('central')->create('guardian_invites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('minor_account_uuid');
            $table->uuid('invited_by_user_uuid');
            $table->string('code', 16)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claimed_by_user_uuid')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('invited_by_user_uuid')->references('uuid')->on('users')->cascadeOnDelete();
            $table->foreign('claimed_by_user_uuid')->references('uuid')->on('users')->nullOnDelete();
            $table->index(['minor_account_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('guardian_invites');
    }
};
