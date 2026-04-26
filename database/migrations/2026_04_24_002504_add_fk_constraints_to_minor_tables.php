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
            if (! Schema::hasColumn('accounts', 'parent_account_id')) {
                $table->uuid('parent_account_id')->nullable();
            }

            if (! $this->hasForeignKey('accounts', 'parent_account_id')) {
                $table->foreign('parent_account_id')
                    ->references('uuid')
                    ->on('accounts')
                    ->onDelete('cascade');
            }
        });

        if (Schema::hasTable('minor_spend_approvals')) {
            Schema::table('minor_spend_approvals', function (Blueprint $table): void {
                $this->ensureForeignKey($table, 'minor_spend_approvals', 'minor_account_uuid', 'accounts', 'uuid', 'cascade');
                $this->ensureForeignKey($table, 'minor_spend_approvals', 'guardian_account_uuid', 'accounts', 'uuid', 'cascade');
                $this->ensureForeignKey($table, 'minor_spend_approvals', 'from_account_uuid', 'accounts', 'uuid', 'restrict');
                $this->ensureForeignKey($table, 'minor_spend_approvals', 'to_account_uuid', 'accounts', 'uuid', 'restrict');
            });
        }

        if (Schema::hasTable('minor_account_lifecycle_transitions')) {
            Schema::table('minor_account_lifecycle_transitions', function (Blueprint $table): void {
                $this->ensureForeignKey($table, 'minor_account_lifecycle_transitions', 'minor_account_uuid', 'accounts', 'uuid', 'cascade');
            });
        }

        if (Schema::hasTable('minor_account_lifecycle_exceptions')) {
            Schema::table('minor_account_lifecycle_exceptions', function (Blueprint $table): void {
                $this->ensureForeignKey($table, 'minor_account_lifecycle_exceptions', 'minor_account_uuid', 'accounts', 'uuid', 'cascade');
                $this->ensureForeignKey($table, 'minor_account_lifecycle_exceptions', 'transition_id', 'minor_account_lifecycle_transitions', 'id', 'set null');
            });
        }

        if (Schema::hasTable('minor_account_lifecycle_exception_acknowledgments')) {
            Schema::table('minor_account_lifecycle_exception_acknowledgments', function (Blueprint $table): void {
                $this->ensureForeignKey($table, 'minor_account_lifecycle_exception_acknowledgments', 'minor_account_lifecycle_exception_id', 'minor_account_lifecycle_exceptions', 'id', 'cascade', 'macle_exception_id_foreign');
                $this->ensureForeignKey($table, 'minor_account_lifecycle_exception_acknowledgments', 'acknowledged_by_user_uuid', 'users', 'uuid', 'restrict', 'macle_ack_by_user_uuid_foreign');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('minor_account_lifecycle_exception_acknowledgments')) {
            Schema::table('minor_account_lifecycle_exception_acknowledgments', function (Blueprint $table): void {
                $table->dropForeign(['minor_account_lifecycle_exception_id']);
                $table->dropForeign(['acknowledged_by_user_uuid']);
            });
        }

        if (Schema::hasTable('minor_account_lifecycle_exceptions')) {
            Schema::table('minor_account_lifecycle_exceptions', function (Blueprint $table): void {
                $table->dropForeign(['minor_account_uuid']);
                $table->dropForeign(['transition_id']);
            });
        }

        if (Schema::hasTable('minor_account_lifecycle_transitions')) {
            Schema::table('minor_account_lifecycle_transitions', function (Blueprint $table): void {
                $table->dropForeign(['minor_account_uuid']);
            });
        }

        if (Schema::hasTable('minor_spend_approvals')) {
            Schema::table('minor_spend_approvals', function (Blueprint $table): void {
                $table->dropForeign(['minor_account_uuid']);
                $table->dropForeign(['guardian_account_uuid']);
                $table->dropForeign(['from_account_uuid']);
                $table->dropForeign(['to_account_uuid']);
            });
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropForeign(['parent_account_id']);
        });
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $fk): bool => in_array($column, $fk['columns'] ?? [], true));
    }

    private function ensureForeignKey(Blueprint $table, string $tableName, string $column, string $refTable, string $refColumn, string $onDelete, ?string $customName = null): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $table->uuid($column);
        }

        if (! $this->hasForeignKey($tableName, $column)) {
            $foreign = $table->foreign($column, $customName);
            $foreign->references($refColumn)->on($refTable)->onDelete($onDelete);
        }
    }
};
