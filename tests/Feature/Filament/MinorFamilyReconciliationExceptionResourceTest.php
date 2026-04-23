<?php

declare(strict_types=1);

use App\Domain\Account\Models\MinorFamilyReconciliationException;
use App\Domain\Account\Models\MinorFamilyReconciliationExceptionAcknowledgment;
use App\Filament\Admin\Resources\MinorFamilyReconciliationExceptionResource\Pages\ListMinorFamilyReconciliationExceptions;
use App\Filament\Admin\Resources\MinorFamilyReconciliationExceptionResource\Pages\ViewMinorFamilyReconciliationException;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    if (Schema::hasTable('minor_family_reconciliation_exceptions')
        && ! Schema::hasColumns('minor_family_reconciliation_exceptions', ['sla_due_at', 'sla_escalated_at'])) {
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_04_23_100410_add_sla_columns_to_minor_family_reconciliation_exceptions_table.php',
            '--force' => true,
        ]);
    }

    if (! Schema::hasTable('minor_family_reconciliation_exception_acknowledgments')) {
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_04_23_100420_create_minor_family_reconciliation_exception_acknowledgments_table.php',
            '--force' => true,
        ]);
    }

    if (Schema::hasTable('minor_family_reconciliation_exceptions')
        && ! Schema::hasColumns('minor_family_reconciliation_exceptions', ['resolved_at'])) {
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_04_23_100430_add_resolved_at_to_minor_family_reconciliation_exceptions_table.php',
            '--force' => true,
        ]);
    }

    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel?->boot();
});

it('allows operators to list and view reconciliation exception artifacts', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('operations-l2');
    $this->actingAs($operator);

    $transactionOwner = User::factory()->create();
    $mtnTransaction = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $transactionOwner->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
        'amount' => '450.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_FAILED,
        'party_msisdn' => '26876123456',
        'mtn_reference_id' => 'recon-ref-001',
    ]);

    $exception = MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $mtnTransaction->id,
        'reason_code' => 'unresolved_outcome',
        'status' => MinorFamilyReconciliationException::STATUS_OPEN,
        'source' => 'callback',
        'occurrence_count' => 1,
        'metadata' => ['transaction_status' => 'failed'],
        'first_seen_at' => now()->subMinutes(5),
        'last_seen_at' => now(),
    ]);

    livewire(ListMinorFamilyReconciliationExceptions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$exception])
        ->assertSee('unresolved_outcome');

    livewire(ViewMinorFamilyReconciliationException::class, ['record' => $exception->getKey()])
        ->assertSuccessful();
});

it('supports conservative acknowledge-manual-review action with audit trail metadata', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('operations-l2');
    $this->actingAs($operator);

    $transactionOwner = User::factory()->create();
    $mtnTransaction = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $transactionOwner->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        'amount' => '300.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        'party_msisdn' => '26876000123',
        'mtn_reference_id' => 'recon-ref-002',
    ]);

    $exception = MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $mtnTransaction->id,
        'reason_code' => 'unresolved_outcome',
        'status' => MinorFamilyReconciliationException::STATUS_OPEN,
        'source' => 'status_poll',
        'occurrence_count' => 2,
        'metadata' => ['transaction_status' => 'successful'],
        'first_seen_at' => now()->subMinutes(8),
        'last_seen_at' => now(),
    ]);

    livewire(ListMinorFamilyReconciliationExceptions::class)
        ->assertSuccessful()
        ->assertTableActionVisible('acknowledge_manual_review', $exception)
        ->callTableAction('acknowledge_manual_review', $exception, [
            'note' => 'Queued for morning reconciliation review.',
        ]);

    /** @var MinorFamilyReconciliationException $fresh */
    $fresh = $exception->fresh();

    expect($fresh->status)->toBe(MinorFamilyReconciliationException::STATUS_OPEN)
        ->and(data_get($fresh->metadata, 'manual_review.acknowledged_by_user_uuid'))->toBe($operator->uuid)
        ->and(data_get($fresh->metadata, 'manual_review.note'))->toBe('Queued for morning reconciliation review.')
        ->and(data_get($fresh->metadata, 'manual_review.acknowledged_at'))->not->toBeNull()
        ->and(data_get($fresh->metadata, 'manual_review.latest_acknowledgment_id'))->not->toBeNull();

    expect(
        MinorFamilyReconciliationExceptionAcknowledgment::query()
            ->where('minor_family_reconciliation_exception_id', $exception->id)
            ->where('acknowledged_by_user_uuid', $operator->uuid)
            ->where('note', 'Queued for morning reconciliation review.')
            ->count(),
    )->toBe(1);

    livewire(ViewMinorFamilyReconciliationException::class, ['record' => $exception->getKey()])
        ->assertSuccessful();
});

it('supports explicit resolve and reopen lifecycle actions with audit trail', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('operations-l2');
    $this->actingAs($operator);

    $transactionOwner = User::factory()->create();
    $mtnTransaction = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $transactionOwner->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
        'amount' => '210.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_FAILED,
        'party_msisdn' => '26876009999',
        'mtn_reference_id' => 'recon-ref-003',
    ]);

    $exception = MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $mtnTransaction->id,
        'reason_code' => 'unresolved_outcome',
        'status' => MinorFamilyReconciliationException::STATUS_OPEN,
        'source' => 'callback',
        'occurrence_count' => 1,
        'metadata' => ['transaction_status' => 'failed'],
        'first_seen_at' => now()->subMinutes(30),
        'last_seen_at' => now()->subMinutes(10),
    ]);

    livewire(ListMinorFamilyReconciliationExceptions::class)
        ->assertSuccessful()
        ->assertTableActionVisible('resolve_exception', $exception)
        ->callTableAction('resolve_exception', $exception, [
            'note' => 'Confirmed closure after reconciliation convergence.',
        ]);

    /** @var MinorFamilyReconciliationException $resolved */
    $resolved = $exception->fresh();
    expect($resolved->status)->toBe(MinorFamilyReconciliationException::STATUS_RESOLVED)
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and(data_get($resolved->metadata, 'resolution.source'))->toBe('filament_manual_resolve')
        ->and(data_get($resolved->metadata, 'resolution.resolved_by_user_uuid'))->toBe($operator->uuid);

    livewire(ListMinorFamilyReconciliationExceptions::class)
        ->assertSuccessful()
        ->assertTableActionVisible('reopen_exception', $resolved)
        ->callTableAction('reopen_exception', $resolved, [
            'note' => 'New callback mismatch evidence requires follow-up.',
        ]);

    /** @var MinorFamilyReconciliationException $reopened */
    $reopened = $resolved->fresh();
    expect($reopened->status)->toBe(MinorFamilyReconciliationException::STATUS_OPEN)
        ->and($reopened->resolved_at)->toBeNull()
        ->and(data_get($reopened->metadata, 'reopened.source'))->toBe('filament_manual_reopen')
        ->and(data_get($reopened->metadata, 'reopened.reopened_by_user_uuid'))->toBe($operator->uuid);

    expect(
        MinorFamilyReconciliationExceptionAcknowledgment::query()
            ->where('minor_family_reconciliation_exception_id', $exception->id)
            ->count(),
    )->toBe(2);
});
