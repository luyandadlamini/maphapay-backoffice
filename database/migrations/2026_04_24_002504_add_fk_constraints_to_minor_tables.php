<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->foreignUuid('parent_account_id')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
        });

        Schema::table('minor_spend_approvals', function (Blueprint $table): void {
            $table->foreignUuid('minor_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
            $table->foreignUuid('guardian_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
            $table->foreignUuid('from_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('restrict');
            $table->foreignUuid('to_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('restrict');
        });

        Schema::table('minor_account_lifecycle_transitions', function (Blueprint $table): void {
            $table->foreignUuid('minor_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
        });

        Schema::table('minor_account_lifecycle_exceptions', function (Blueprint $table): void {
            $table->foreignUuid('minor_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
            $table->foreignUuid('transition_id')
                ->references('uuid')
                ->on('minor_account_lifecycle_transitions')
                ->onDelete('setnull');
        });

        Schema::table('minor_account_lifecycle_exception_acknowledgments', function (Blueprint $table): void {
            $table->foreignUuid('minor_account_lifecycle_exception_id')
                ->references('id')
                ->on('minor_account_lifecycle_exceptions')
                ->onDelete('cascade');
            $table->foreignUuid('acknowledged_by_user_uuid')
                ->references('uuid')
                ->on('users')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('minor_account_lifecycle_exception_acknowledgments', function (Blueprint $table): void {
            $table->dropForeign(['minor_account_lifecycle_exception_id']);
            $table->dropForeign(['acknowledged_by_user_uuid']);
        });

        Schema::table('minor_account_lifecycle_exceptions', function (Blueprint $table): void {
            $table->dropForeign(['minor_account_uuid']);
            $table->dropForeign(['transition_id']);
        });

        Schema::table('minor_account_lifecycle_transitions', function (Blueprint $table): void {
            $table->dropForeign(['minor_account_uuid']);
        });

        Schema::table('minor_spend_approvals', function (Blueprint $table): void {
            $table->dropForeign(['minor_account_uuid']);
            $table->dropForeign(['guardian_account_uuid']);
            $table->dropForeign(['from_account_uuid']);
            $table->dropForeign(['to_account_uuid']);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropForeign(['parent_account_id']);
        });
    }
};