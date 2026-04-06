<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Secure key storage table
        Schema::create('secure_key_storage', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_id');
            $table->text('encrypted_data');
            $table->string('auth_tag'); // For AES-GCM authentication
            $table->string('iv'); // Initialization vector
            $table->string('salt'); // Salt for key derivation
            $table->integer('key_version')->default(1);
            $table->string('storage_type')->default('database'); // database, hsm, cloud_hsm
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'is_active']);
            $table->index('key_version');
            // Note: We handle uniqueness of active keys in the application logic
        });

        // Key access audit logs
        Schema::create('key_access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_id');
            $table->string('user_id');
            $table->string('action'); // store, retrieve, rotate, temp_store, temp_retrieve
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('accessed_at');

            $table->index(['wallet_id', 'action']);
            $table->index(['user_id', 'accessed_at']);
            $table->index('action');
        });

        // Key rotation history
        Schema::create('key_rotation_history', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_id');
            $table->string('rotated_by');
            $table->string('reason')->nullable();
            $table->integer('old_version');
            $table->integer('new_version');
            $table->json('metadata')->nullable();
            $table->dateTime('rotated_at');

            $table->index('wallet_id');
            $table->index('rotated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('key_rotation_history');
        Schema::dropIfExists('key_access_logs');
        Schema::dropIfExists('secure_key_storage');
    }
};
