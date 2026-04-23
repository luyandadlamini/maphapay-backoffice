<?php

declare(strict_types=1);

use App\Domain\Account\Models\MinorAccountLifecycleException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    if (! Schema::hasTable('minor_account_lifecycle_transitions')) {
        (require base_path('database/migrations/tenant/2026_04_23_110000_create_minor_account_lifecycle_transitions_table.php'))->up();
    }
    if (! Schema::hasTable('minor_account_lifecycle_exceptions')) {
        (require base_path('database/migrations/tenant/2026_04_23_110100_create_minor_account_lifecycle_exceptions_table.php'))->up();
    }
    if (! Schema::hasTable('minor_account_lifecycle_exception_acknowledgments')) {
        (require base_path('database/migrations/tenant/2026_04_23_110110_create_minor_account_lifecycle_exception_acknowledgments_table.php'))->up();
    }
    if (! Schema::hasColumn('accounts', 'minor_transition_state') || ! Schema::hasColumn('accounts', 'minor_transition_effective_at')) {
        (require base_path('database/migrations/tenant/2026_04_23_110120_add_minor_transition_columns_to_accounts_table.php'))->up();
    }
});

it('sets sla_escalated_at on overdue open lifecycle exceptions', function (): void {
    $exception = MinorAccountLifecycleException::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-lifecycle',
        'minor_account_uuid' => (string) Str::uuid(),
        'reason_code' => 'adult_kyc_not_ready',
        'status' => MinorAccountLifecycleException::STATUS_OPEN,
        'source' => 'scheduler',
        'occurrence_count' => 1,
        'metadata' => [],
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now()->subHour(),
        'sla_due_at' => now()->subMinute(),
        'sla_escalated_at' => null,
    ]);

    $this->artisan('minor-accounts:lifecycle-exceptions-flag-sla-breaches')
        ->assertSuccessful();

    expect($exception->fresh()?->sla_escalated_at)->not->toBeNull();
});
