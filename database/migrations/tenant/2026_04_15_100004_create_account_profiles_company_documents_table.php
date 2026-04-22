<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('account_profiles_company_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_profile_id'); // References account_profiles_company
            $table->string('document_type'); // certificate_of_incorporation, form_j, memo_articles, directors_id, trading_license, proof_of_address, bank_statement
            $table->string('file_path');
            $table->string('file_hash')->nullable(); // SHA-256 hash for tamper detection
            $table->string('upload_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('original_file_name');
            $table->string('mime_type');
            $table->integer('file_size')->nullable(); // in bytes
            $table->string('status')->default('pending'); // pending, verified, rejected
            $table->text('rejection_reason')->nullable();
            $table->uuid('uploaded_by_user_uuid');
            $table->timestamp('uploaded_at');
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verified_by_user_uuid')->nullable();
            $table->timestamps();

            $table->index('company_profile_id');
            $table->index('document_type');
            $table->index('status');
            $table->index('uploaded_by_user_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_profiles_company_documents');
    }
};
