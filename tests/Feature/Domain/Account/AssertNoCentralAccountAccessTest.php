<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Account;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for maphapay:assert-no-central-account-access.
 *
 * These tests verify the guard's behaviour when the legacy tables do NOT yet
 * exist (the pre-migration / pre-Phase-7 state that CI will see on most runs).
 *
 * We deliberately do NOT create the legacy tables in these tests — the command
 * must exit 0 and log a notice in that scenario.
 */
class AssertNoCentralAccountAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Remove any stale baseline file from a previous run.
        Storage::delete('central-account-baseline.json');
    }

    protected function tearDown(): void
    {
        Storage::delete('central-account-baseline.json');

        parent::tearDown();
    }

    #[Test]
    public function exits_zero_when_legacy_tables_do_not_exist(): void
    {
        // Verify neither legacy table exists in the test DB (pre-migration state).
        $this->assertFalse(
            DB::connection('mysql')->getSchemaBuilder()->hasTable('accounts_legacy_pre_canonicalization'),
            'Legacy accounts table must not exist for this test to be meaningful',
        );

        $this->assertFalse(
            DB::connection('mysql')->getSchemaBuilder()->hasTable('account_balances_legacy_pre_canonicalization'),
            'Legacy account_balances table must not exist for this test to be meaningful',
        );

        $exitCode = Artisan::call('maphapay:assert-no-central-account-access');

        $this->assertSame(0, $exitCode, 'Command must exit 0 when legacy tables are absent (pre-migration state)');
    }

    #[Test]
    public function does_not_write_baseline_when_tables_absent(): void
    {
        Artisan::call('maphapay:assert-no-central-account-access');

        $this->assertFalse(
            Storage::exists('central-account-baseline.json'),
            'Baseline file must NOT be created when legacy tables do not exist',
        );
    }
}
