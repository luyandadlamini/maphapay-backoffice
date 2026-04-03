<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Large]
class CreateAuthorizedTransactionBiometricChallengesTableMigrationTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function it_repairs_a_partially_created_table_when_the_table_already_exists(): void
    {
        Schema::dropIfExists('authorized_transaction_biometric_challenges');
        $this->ensureMobileDevicesTable();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('authorized_transactions'));
        $this->assertTrue(Schema::hasTable('mobile_devices'));

        Schema::create('authorized_transaction_biometric_challenges', function ($table): void {
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

        $migration = require base_path('database/migrations/2026_04_03_170000_create_authorized_transaction_biometric_challenges_table.php');

        $migration->up();

        $this->assertTrue($this->hasIndex('authorized_transaction_biometric_challenges', 'auth_txn_bio_chal_challenge_unique'));
        $this->assertTrue($this->hasIndex('authorized_transaction_biometric_challenges', 'auth_txn_bio_challenge_txn_status'));
        $this->assertTrue($this->hasIndex('authorized_transaction_biometric_challenges', 'auth_txn_bio_challenge_device_status'));
        $this->assertTrue($this->hasIndex('authorized_transaction_biometric_challenges', 'auth_txn_bio_challenge_value_status'));
        $this->assertTrue($this->hasIndex('authorized_transaction_biometric_challenges', 'auth_txn_bio_challenge_expires_at'));
        $this->assertTrue($this->hasForeignKey('authorized_transaction_biometric_challenges', 'auth_txn_bio_chal_auth_txn_fk'));
        $this->assertTrue($this->hasForeignKey('authorized_transaction_biometric_challenges', 'auth_txn_bio_chal_device_fk'));
        $this->assertTrue($this->hasForeignKey('authorized_transaction_biometric_challenges', 'auth_txn_bio_chal_user_fk'));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('authorized_transaction_biometric_challenges');

        parent::tearDown();
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function ensureMobileDevicesTable(): void
    {
        if (Schema::hasTable('mobile_devices')) {
            return;
        }

        Schema::create('mobile_devices', function ($table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 100)->unique();
            $table->enum('platform', ['ios', 'android']);
            $table->string('app_version', 20);
            $table->boolean('biometric_enabled')->default(false);
            $table->timestamps();
        });
    }

    private function hasForeignKey(string $table, string $constraintName): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraintName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
}
