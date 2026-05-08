<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class AuditLogAppendOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }

        if (! Schema::hasTable('card_audit_logs')) {
            $this->markTestSkipped('card_audit_logs table does not exist — run migrations first.');
        }
    }

    #[Test]
    public function it_has_no_updated_at_column(): void
    {
        // Append-only tables must not have updated_at.
        // If updated_at exists, it implies the ORM is allowed to mutate rows.
        $this->assertFalse(
            Schema::hasColumn('card_audit_logs', 'updated_at'),
            'card_audit_logs must not have updated_at — it is an append-only table.',
        );
    }

    #[Test]
    public function it_has_created_at_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('card_audit_logs', 'created_at'),
            'card_audit_logs must have created_at.',
        );
    }

    #[Test]
    public function it_allows_inserts(): void
    {
        $id = (string) Str::uuid();

        DB::table('card_audit_logs')->insert([
            'id'          => $id,
            'actor_type'  => 'system',
            'actor_id'    => null,
            'action'      => 'test.schema_assertion',
            'entity_type' => 'test',
            'entity_id'   => null,
            'created_at'  => now(),
        ]);

        $this->assertNotNull(
            DB::table('card_audit_logs')->where('id', $id)->first(),
        );

        // Clean up test row
        DB::table('card_audit_logs')->where('id', $id)->delete();
    }

    #[Test]
    public function it_documents_update_is_blocked_at_application_layer(): void
    {
        // DB-level UPDATE/DELETE prevention is enforced at the application layer,
        // not via a DB trigger in this migration (Phase 11 security audit adds
        // the DB-level verification). This test documents the schema contract:
        // card_audit_logs has no updated_at and must only be written via
        // CardAuditLogService::append() which never issues UPDATE or DELETE.
        //
        // Phase 11 task 11.3 will manually verify that the audit service
        // rejects PAN-bearing content and that no update path exists.
        $this->assertFalse(
            Schema::hasColumn('card_audit_logs', 'updated_at'),
            'Absence of updated_at is the schema-level append-only contract.',
        );
    }
}
