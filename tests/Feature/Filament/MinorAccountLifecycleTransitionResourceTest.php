<?php

declare(strict_types=1);

use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use App\Filament\Admin\Resources\MinorAccountLifecycleTransitionResource\Pages\ListMinorAccountLifecycleTransitions;
use App\Filament\Admin\Resources\MinorAccountLifecycleTransitionResource\Pages\ViewMinorAccountLifecycleTransition;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
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

it('allows operators to list and view lifecycle transitions', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('operations-l2');
    $this->actingAs($operator);

    $transition = MinorAccountLifecycleTransition::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-filament',
        'minor_account_uuid' => (string) Str::uuid(),
        'transition_type' => MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE,
        'state' => MinorAccountLifecycleTransition::STATE_PENDING,
        'effective_at' => now(),
        'metadata' => ['target_tier' => 'rise'],
    ]);

    livewire(ListMinorAccountLifecycleTransitions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$transition]);

    livewire(ViewMinorAccountLifecycleTransition::class, ['record' => $transition->getKey()])
        ->assertSuccessful();
});
