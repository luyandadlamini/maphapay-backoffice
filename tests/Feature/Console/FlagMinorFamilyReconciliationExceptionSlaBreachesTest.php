<?php

declare(strict_types=1);

use App\Domain\Account\Models\MinorFamilyReconciliationException;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
});

it('sets sla_escalated_at on open exceptions whose sla_due_at is in the past', function (): void {
    $owner = User::factory()->create();
    $mtnTransaction = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $owner->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        'amount' => '75.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        'party_msisdn' => '26876155555',
        'mtn_reference_id' => 'sla-ref-open',
    ]);

    $exception = MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $mtnTransaction->id,
        'reason_code' => 'unresolved_outcome',
        'status' => MinorFamilyReconciliationException::STATUS_OPEN,
        'source' => 'callback',
        'occurrence_count' => 1,
        'metadata' => [],
        'first_seen_at' => now()->subDays(2),
        'last_seen_at' => now()->subDay(),
        'sla_due_at' => now()->subHour(),
        'sla_escalated_at' => null,
    ]);

    $this->artisan('minor-family:reconciliation-exceptions-flag-sla-breaches')
        ->assertSuccessful();

    $exception->refresh();
    expect($exception->sla_escalated_at)->not->toBeNull();
});

it('does not flag resolved exceptions or breaches already escalated', function (): void {
    $owner = User::factory()->create();

    $txnResolved = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $owner->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        'amount' => '10.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        'party_msisdn' => '26876166666',
        'mtn_reference_id' => 'sla-ref-resolved',
    ]);

    $resolved = MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $txnResolved->id,
        'reason_code' => 'missing_tenant_context',
        'status' => MinorFamilyReconciliationException::STATUS_RESOLVED,
        'source' => 'callback',
        'occurrence_count' => 1,
        'metadata' => [],
        'first_seen_at' => now()->subDays(3),
        'last_seen_at' => now()->subDays(2),
        'sla_due_at' => now()->subDays(2),
        'sla_escalated_at' => null,
    ]);

    $txnFuture = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $owner->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        'amount' => '11.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        'party_msisdn' => '26876177777',
        'mtn_reference_id' => 'sla-ref-future',
    ]);

    $futureSla = MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $txnFuture->id,
        'reason_code' => 'unresolved_outcome',
        'status' => MinorFamilyReconciliationException::STATUS_OPEN,
        'source' => 'status_poll',
        'occurrence_count' => 1,
        'metadata' => [],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'sla_due_at' => now()->addDay(),
        'sla_escalated_at' => null,
    ]);

    $txnAlready = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $owner->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        'amount' => '12.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        'party_msisdn' => '26876188888',
        'mtn_reference_id' => 'sla-ref-already',
    ]);

    $alreadyFlaggedAt = now()->subMinutes(30);
    $already = MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $txnAlready->id,
        'reason_code' => 'unresolved_outcome',
        'status' => MinorFamilyReconciliationException::STATUS_OPEN,
        'source' => 'callback',
        'occurrence_count' => 1,
        'metadata' => [],
        'first_seen_at' => now()->subDays(2),
        'last_seen_at' => now()->subDay(),
        'sla_due_at' => now()->subHours(3),
        'sla_escalated_at' => $alreadyFlaggedAt,
    ]);

    $this->artisan('minor-family:reconciliation-exceptions-flag-sla-breaches')
        ->assertSuccessful();

    $resolved->refresh();
    $futureSla->refresh();
    $already->refresh();

    expect($resolved->sla_escalated_at)->toBeNull()
        ->and($futureSla->sla_escalated_at)->toBeNull()
        ->and($already->sla_escalated_at)->not->toBeNull()
        ->and(abs($already->sla_escalated_at->diffInSeconds($alreadyFlaggedAt)))->toBeLessThanOrEqual(1);
});
