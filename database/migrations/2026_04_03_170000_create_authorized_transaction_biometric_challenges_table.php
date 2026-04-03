<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const TABLE = 'authorized_transaction_biometric_challenges';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('authorized_transaction_id');
                $table->uuid('mobile_device_id');
                $table->foreignId('user_id');
                $table->string('challenge', 64);
                $table->enum('status', ['pending', 'verified', 'expired', 'failed'])->default('pending');
                $table->string('ip_address', 45)->nullable();
                $table->dateTime('expires_at');
                $table->dateTime('verified_at')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureUniqueIndex('challenge', 'auth_txn_bio_chal_challenge_unique');
        $this->ensureIndex(['authorized_transaction_id', 'status'], 'auth_txn_bio_challenge_txn_status');
        $this->ensureIndex(['mobile_device_id', 'status'], 'auth_txn_bio_challenge_device_status');
        $this->ensureIndex(['challenge', 'status'], 'auth_txn_bio_challenge_value_status');
        $this->ensureIndex('expires_at', 'auth_txn_bio_challenge_expires_at');
        $this->ensureForeignKey('authorized_transaction_id', 'auth_txn_bio_chal_auth_txn_fk', 'authorized_transactions');
        $this->ensureForeignKey('mobile_device_id', 'auth_txn_bio_chal_device_fk', 'mobile_devices');
        $this->ensureForeignKey('user_id', 'auth_txn_bio_chal_user_fk', 'users');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensureUniqueIndex(string $column, string $indexName): void
    {
        if ($this->hasIndex($indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($column, $indexName): void {
            $table->unique($column, $indexName);
        });
    }

    private function ensureIndex(array|string $columns, string $indexName): void
    {
        if ($this->hasIndex($indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function ensureForeignKey(string $column, string $foreignKeyName, string $referencedTable): void
    {
        if ($this->hasForeignKey($foreignKeyName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($column, $foreignKeyName, $referencedTable): void {
            $table->foreign($column, $foreignKeyName)
                ->references('id')
                ->on($referencedTable)
                ->cascadeOnDelete();
        });
    }

    private function hasIndex(string $indexName): bool
    {
        $connection = Schema::getConnection();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $connection->getDatabaseName())
            ->where('table_name', self::TABLE)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function hasForeignKey(string $foreignKeyName): bool
    {
        $connection = Schema::getConnection();

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $connection->getDatabaseName())
            ->where('table_name', self::TABLE)
            ->where('constraint_name', $foreignKeyName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
