<?php

declare(strict_types=1);

use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Filament\Admin\Resources\MinorAccountLifecycleExceptionResource\Pages\ListMinorAccountLifecycleExceptions;
use App\Filament\Admin\Resources\MinorAccountLifecycleExceptionResource\Pages\ViewMinorAccountLifecycleException;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

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

    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel?->boot();
});

it('allows operators to review and resolve lifecycle exceptions', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('operations-l2');
    $this->actingAs($operator);

    $exception = MinorAccountLifecycleException::query()->create([
        'id'                 => (string) Str::uuid(),
        'tenant_id'          => 'tenant-filament',
        'minor_account_uuid' => (string) Str::uuid(),
        'transition_id'      => null,
        'reason_code'        => 'adult_kyc_not_ready',
        'status'             => MinorAccountLifecycleException::STATUS_OPEN,
        'source'             => 'scheduler',
        'occurrence_count'   => 1,
        'metadata'           => [],
        'first_seen_at'      => now()->subHour(),
        'last_seen_at'       => now(),
        'sla_due_at'         => now()->addDay(),
    ]);

    livewire(ListMinorAccountLifecycleExceptions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$exception])
        ->callTableAction('acknowledge_exception', $exception, [
            'note' => 'Operator acknowledged this blocker.',
        ])
        ->callTableAction('resolve_exception', $exception->fresh(), [
            'note' => 'Resolved after manual review.',
        ]);

    expect($exception->fresh()?->status)->toBe(MinorAccountLifecycleException::STATUS_RESOLVED);

    livewire(ViewMinorAccountLifecycleException::class, ['record' => $exception->getKey()])
        ->assertSuccessful();
});
