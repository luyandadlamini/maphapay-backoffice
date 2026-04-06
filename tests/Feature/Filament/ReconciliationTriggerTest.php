<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\ReconciliationReportResource\Pages\ListReconciliationReports;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

it('finance-lead can see run reconciliation action', function () {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    livewire(ListReconciliationReports::class)
        ->assertSuccessful()
        ->assertTableActionExists('runReconciliation');
});

it('operations-l2 cannot see run reconciliation action', function () {
    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $this->actingAs($ops);

    livewire(ListReconciliationReports::class)
        ->assertTableActionHidden('runReconciliation');
});

it('finance-lead can export reconciliation reports as CSV', function () {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    livewire(ListReconciliationReports::class)
        ->assertSuccessful()
        ->assertTableBulkActionExists('exportCsv');
});
