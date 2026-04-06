<?php

declare(strict_types=1);

use App\Domain\Fraud\Models\AnomalyDetection;
use App\Domain\MobilePayment\Enums\PaymentIntentStatus;
use App\Domain\MobilePayment\Models\PaymentIntent;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $permissions = ['view-transactions', 'view-anomalies', 'manage-cards', 'moderate-social'];
    foreach ($permissions as $p) {
        Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }

    $user = User::factory()->create();
    $user->syncPermissions($permissions);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('can render commerce exception widget', function () {
    // @phpstan-ignore argument.type
    PaymentIntent::factory()->create([
        'status'  => PaymentIntentStatus::FAILED,
        'user_id' => $this->user->id,
    ]);

    livewire(App\Filament\Admin\Widgets\CommerceExceptionWidget::class)
        ->assertCanSeeTableRecords(PaymentIntent::where('status', PaymentIntentStatus::FAILED)->get())
        ->assertStatus(200);
});

it('can render fraud resolution rate widget', function () {
    AnomalyDetection::factory()->count(3)->create([
        'triage_status' => AnomalyDetection::TRIAGE_STATUS_DETECTED,
    ]);

    AnomalyDetection::factory()->count(2)->create([
        'triage_status' => AnomalyDetection::TRIAGE_STATUS_RESOLVED,
    ]);

    livewire(App\Filament\Admin\Widgets\FraudResolutionRateWidget::class)
        ->assertSee('Detected Anomalies')
        ->assertSee('3')
        ->assertSee('Triage Resolution Rate')
        ->assertStatus(200);
});
