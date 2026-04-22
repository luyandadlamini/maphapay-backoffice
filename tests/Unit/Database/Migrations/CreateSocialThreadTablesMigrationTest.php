<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Migrations;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateSocialThreadTablesMigrationTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function it_creates_the_social_thread_tables_and_columns(): void
    {
        $this->dropSocialThreadTables();

        $migrations = [
            '2026_04_04_000001_create_threads_table.php',
            '2026_04_04_000002_create_thread_participants_table.php',
            '2026_04_04_000003_create_messages_table.php',
            '2026_04_04_000004_create_message_reads_table.php',
            '2026_04_04_000005_create_bill_splits_table.php',
            '2026_04_04_000006_create_bill_split_participants_table.php',
        ];

        foreach ($migrations as $migrationFile) {
            $migration = require base_path("database/migrations/{$migrationFile}");
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('threads'));
        $this->assertTrue(Schema::hasColumns('threads', [
            'type',
            'name',
            'avatar_url',
            'created_by',
            'max_participants',
            'settings',
        ]));

        $this->assertTrue(Schema::hasTable('thread_participants'));
        $this->assertTrue(Schema::hasColumns('thread_participants', [
            'thread_id',
            'user_id',
            'role',
            'joined_at',
            'left_at',
            'added_by',
        ]));

        $this->assertTrue(Schema::hasTable('messages'));
        $this->assertTrue(Schema::hasColumns('messages', [
            'thread_id',
            'sender_id',
            'type',
            'text',
            'payload',
            'idempotency_key',
            'status',
            'created_at',
        ]));

        $this->assertTrue(Schema::hasTable('message_reads'));
        $this->assertTrue(Schema::hasColumns('message_reads', [
            'thread_id',
            'user_id',
            'last_read_message_id',
            'read_at',
        ]));

        $this->assertTrue(Schema::hasTable('bill_splits'));
        $this->assertTrue(Schema::hasColumns('bill_splits', [
            'message_id',
            'thread_id',
            'created_by',
            'description',
            'total_amount',
            'asset_code',
            'split_method',
            'status',
        ]));

        $this->assertTrue(Schema::hasTable('bill_split_participants'));
        $this->assertTrue(Schema::hasColumns('bill_split_participants', [
            'bill_split_id',
            'user_id',
            'amount',
            'status',
            'paid_at',
        ]));
    }

    protected function tearDown(): void
    {
        $this->dropSocialThreadTables();

        parent::tearDown();
    }

    private function dropSocialThreadTables(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('group_pocket_contributions');
        Schema::dropIfExists('group_pocket_withdrawal_requests');
        Schema::dropIfExists('group_pockets');
        Schema::dropIfExists('bill_split_participants');
        Schema::dropIfExists('bill_splits');
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('thread_participants');
        Schema::dropIfExists('threads');
        Schema::enableForeignKeyConstraints();
    }
}
