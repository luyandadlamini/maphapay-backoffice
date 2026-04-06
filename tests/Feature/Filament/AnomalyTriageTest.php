<?php

declare(strict_types=1);

use App\Domain\Fraud\Models\AnomalyDetection;
use App\Filament\Admin\Resources\AnomalyDetectionResource\Pages\ListAnomalyDetections;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('fraud-analyst can assign an anomaly to themselves', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole('fraud-analyst');
    $this->actingAs($analyst);

    $anomaly = AnomalyDetection::factory()->create(['triage_status' => 'detected']);

    livewire(ListAnomalyDetections::class)
        ->callTableAction('assign', $anomaly, data: ['assigned_to' => $analyst->id])
        ->assertHasNoTableActionErrors();

    expect($anomaly->fresh()->triage_status)->toBe('under_review');
    expect($anomaly->fresh()->assigned_to)->toBe($analyst->id);
});

it('fraud-analyst can resolve an under_review anomaly', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole('fraud-analyst');
    $this->actingAs($analyst);

    $anomaly = AnomalyDetection::factory()->create([
        'triage_status' => 'under_review',
        'assigned_to'   => $analyst->id,
    ]);

    livewire(ListAnomalyDetections::class)
        ->callTableAction('resolve', $anomaly, data: [
            'resolution_type'  => 'fraud',
            'resolution_notes' => 'Confirmed suspicious pattern.',
        ])
        ->assertHasNoTableActionErrors();

    expect($anomaly->fresh()->triage_status)->toBe('resolved');
    expect($anomaly->fresh()->resolved_by)->toBe($analyst->id);
});

it('fraud-analyst can mark an anomaly as false positive', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole('fraud-analyst');
    $this->actingAs($analyst);

    $anomaly = AnomalyDetection::factory()->create(['triage_status' => 'detected']);

    livewire(ListAnomalyDetections::class)
        ->callTableAction('mark_false_positive', $anomaly)
        ->assertHasNoTableActionErrors();

    expect($anomaly->fresh()->triage_status)->toBe('false_positive');
});
