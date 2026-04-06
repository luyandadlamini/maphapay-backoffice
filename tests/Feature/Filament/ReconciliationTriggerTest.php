<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\ReconciliationReportResource\Pages\ListReconciliationReports;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament\Facades\Filament::getPanel('admin');
    Filament\Facades\Filament::setCurrentPanel($panel);
    Filament\Facades\Filament::setServingStatus(true);
    $panel->boot();
});

it('finance-lead can see run reconciliation action', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    // run_reconciliation is a page header action, not a table action
    livewire(ListReconciliationReports::class)
        ->assertSuccessful()
        ->assertActionVisible('run_reconciliation');
});

it('operations-l2 cannot see run reconciliation action', function (): void {
    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $this->actingAs($ops);

    livewire(ListReconciliationReports::class)
        ->assertSuccessful()
        ->assertActionHidden('run_reconciliation');
});

it('finance-lead can export reconciliation reports as CSV', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    livewire(ListReconciliationReports::class)
        ->assertSuccessful()
        ->assertTableBulkActionExists('exportCsv');
});
