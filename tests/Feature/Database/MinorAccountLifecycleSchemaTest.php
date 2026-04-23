<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

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

it('creates the minor lifecycle tables and account transition columns', function (): void {
    expect(Schema::hasTable('minor_account_lifecycle_transitions'))->toBeTrue()
        ->and(Schema::hasTable('minor_account_lifecycle_exceptions'))->toBeTrue()
        ->and(Schema::hasTable('minor_account_lifecycle_exception_acknowledgments'))->toBeTrue()
        ->and(Schema::hasColumns('accounts', ['minor_transition_state', 'minor_transition_effective_at']))->toBeTrue();
});
