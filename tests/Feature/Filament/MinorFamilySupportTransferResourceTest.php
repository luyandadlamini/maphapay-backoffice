<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilyReconciliationException;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Account\Services\MinorFamilyReconciliationOutcome;
use App\Domain\Account\Services\MinorFamilyReconciliationService;
use App\Filament\Admin\Resources\MinorFamilyFundingAttemptResource\Pages\ListMinorFamilyFundingAttempts;
use App\Filament\Admin\Resources\MinorFamilyFundingAttemptResource\Pages\ViewMinorFamilyFundingAttempt;
use App\Filament\Admin\Resources\MinorFamilySupportTransferResource\Pages\ListMinorFamilySupportTransfers;
use App\Filament\Admin\Resources\MinorFamilySupportTransferResource\Pages\ViewMinorFamilySupportTransfer;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel?->boot();
});

it('allows authorized operators to list and view support transfers', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('finance-lead');
    $this->actingAs($operator);

    $minorOwner = User::factory()->create();
    $guardian = User::factory()->create();

    $minorAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Minor Wallet',
        'user_uuid' => $minorOwner->uuid,
    ]);

    $guardianAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Guardian Wallet',
        'user_uuid' => $guardian->uuid,
    ]);

    $supportTransfer = MinorFamilySupportTransfer::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-filament-tests',
        'minor_account_uuid' => $minorAccount->uuid,
        'actor_user_uuid' => $guardian->uuid,
        'source_account_uuid' => $guardianAccount->uuid,
        'status' => MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED,
        'provider_name' => 'mtn_momo',
        'recipient_name' => 'Uncle Sibusiso',
        'recipient_msisdn' => '26876123456',
        'amount' => '230.00',
        'asset_code' => 'SZL',
        'note' => 'Support transfer for family emergency',
        'provider_reference_id' => 'provider-ref-1001',
        'mtn_momo_transaction_id' => null,
        'wallet_refunded_at' => null,
        'failed_reason' => 'Provider callback mismatch',
        'idempotency_key' => 'idem-'.Str::lower(Str::random(16)),
    ]);

    livewire(ListMinorFamilySupportTransfers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$supportTransfer])
        ->assertSee('provider-ref-1001')
        ->assertSee('Provider callback mismatch')
        ->assertTableActionDoesNotExist('retry')
        ->assertTableActionDoesNotExist('refund');

    livewire(ViewMinorFamilySupportTransfer::class, ['record' => $supportTransfer->getKey()])
        ->assertSuccessful();
});

it('surfaces funding attempt reconciliation and audit context on list and view pages', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('finance-lead');
    $this->actingAs($operator);

    $minorOwner = User::factory()->create();
    $guardian = User::factory()->create();

    $minorAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Minor Wallet',
        'user_uuid' => $minorOwner->uuid,
    ]);

    $guardianAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Guardian Wallet',
        'user_uuid' => $guardian->uuid,
    ]);

    $fundingLink = MinorFamilyFundingLink::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-filament-tests',
        'minor_account_uuid' => $minorAccount->uuid,
        'created_by_user_uuid' => $guardian->uuid,
        'created_by_account_uuid' => $guardianAccount->uuid,
        'title' => 'School fundraiser',
        'note' => 'Top-up for school activities',
        'token' => 'minor-link-'.Str::lower(Str::random(16)),
        'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
        'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
        'fixed_amount' => null,
        'target_amount' => '750.00',
        'collected_amount' => '420.00',
        'asset_code' => 'SZL',
        'provider_options' => [MinorFamilyFundingLink::DEFAULT_PROVIDER],
        'expires_at' => now()->addDays(10),
    ]);

    $attempt = MinorFamilyFundingAttempt::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-filament-tests',
        'funding_link_uuid' => $fundingLink->id,
        'minor_account_uuid' => $minorAccount->uuid,
        'status' => MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED,
        'sponsor_name' => 'Auntie Thandi',
        'sponsor_msisdn' => '26876111222',
        'amount' => '120.00',
        'asset_code' => 'SZL',
        'provider_name' => 'mtn_momo',
        'provider_reference_id' => 'attempt-ref-990',
        'mtn_momo_transaction_id' => null,
        'wallet_credited_at' => null,
        'failed_reason' => 'Wallet settlement pending reconciliation',
        'dedupe_hash' => hash('sha256', Str::random(24)),
    ]);

    livewire(ListMinorFamilyFundingAttempts::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$attempt])
        ->assertSee('successful_uncredited')
        ->assertSee('attempt-ref-990')
        ->assertSee('Wallet settlement pending reconciliation');

    livewire(ViewMinorFamilyFundingAttempt::class, ['record' => $attempt->getKey()])
        ->assertSuccessful();
});

it('offers safe settlement retry for successful-uncredited funding attempts via reconciliation service', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('finance-lead');
    $this->actingAs($operator);

    $minorOwner = User::factory()->create();
    $guardian = User::factory()->create();

    $minorAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Minor Wallet',
        'user_uuid' => $minorOwner->uuid,
    ]);

    $guardianAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Guardian Wallet',
        'user_uuid' => $guardian->uuid,
    ]);

    $fundingLink = MinorFamilyFundingLink::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-filament-tests',
        'minor_account_uuid' => $minorAccount->uuid,
        'created_by_user_uuid' => $guardian->uuid,
        'created_by_account_uuid' => $guardianAccount->uuid,
        'title' => 'Emergency support',
        'note' => 'Family contribution',
        'token' => 'minor-link-'.Str::lower(Str::random(16)),
        'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
        'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
        'fixed_amount' => null,
        'target_amount' => '900.00',
        'collected_amount' => '150.00',
        'asset_code' => 'SZL',
        'provider_options' => [MinorFamilyFundingLink::DEFAULT_PROVIDER],
        'expires_at' => now()->addDays(8),
    ]);

    $mtnTransaction = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $guardian->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        'amount' => '120.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        'party_msisdn' => '26876111222',
        'mtn_reference_id' => 'attempt-ref-901',
    ]);

    $attempt = MinorFamilyFundingAttempt::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-filament-tests',
        'funding_link_uuid' => $fundingLink->id,
        'minor_account_uuid' => $minorAccount->uuid,
        'status' => MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED,
        'sponsor_name' => 'Auntie Thandi',
        'sponsor_msisdn' => '26876111222',
        'amount' => '120.00',
        'asset_code' => 'SZL',
        'provider_name' => 'mtn_momo',
        'provider_reference_id' => 'attempt-ref-901',
        'mtn_momo_transaction_id' => $mtnTransaction->id,
        'wallet_credited_at' => null,
        'failed_reason' => 'wallet_credit_failed',
        'dedupe_hash' => hash('sha256', Str::random(24)),
    ]);

    MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $mtnTransaction->id,
        'reason_code' => 'unresolved_outcome',
        'status' => MinorFamilyReconciliationException::STATUS_OPEN,
        'source' => 'callback',
        'occurrence_count' => 1,
        'metadata' => ['status' => 'successful_uncredited'],
        'first_seen_at' => now()->subMinute(),
        'last_seen_at' => now(),
    ]);

    $mock = \Mockery::mock(MinorFamilyReconciliationService::class);
    $mock->shouldReceive('reconcile')
        ->once()
        ->with(
            \Mockery::on(fn (MtnMomoTransaction $transaction): bool => $transaction->id === $mtnTransaction->id),
            'filament_retry_settlement'
        )
        ->andReturn(MinorFamilyReconciliationOutcome::UNRESOLVED);
    $this->app->instance(MinorFamilyReconciliationService::class, $mock);

    livewire(ListMinorFamilyFundingAttempts::class)
        ->assertSuccessful()
        ->assertTableActionVisible('retry_settlement', $attempt)
        ->assertTableActionVisible('view_exception_artifact', $attempt)
        ->callTableAction('retry_settlement', $attempt);

    expect($attempt->fresh()?->status)->toBe(MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED);
});

it('offers safe settlement retry for failed-unreconciled support transfers via reconciliation service', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('finance-lead');
    $this->actingAs($operator);

    $minorOwner = User::factory()->create();
    $guardian = User::factory()->create();

    $minorAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Minor Wallet',
        'user_uuid' => $minorOwner->uuid,
    ]);

    $guardianAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Guardian Wallet',
        'user_uuid' => $guardian->uuid,
    ]);

    $mtnTransaction = MtnMomoTransaction::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $guardian->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
        'amount' => '230.00',
        'currency' => 'SZL',
        'status' => MtnMomoTransaction::STATUS_FAILED,
        'party_msisdn' => '26876123456',
        'mtn_reference_id' => 'provider-ref-1001',
        'wallet_debited_at' => now()->subMinutes(10),
    ]);

    $supportTransfer = MinorFamilySupportTransfer::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-filament-tests',
        'minor_account_uuid' => $minorAccount->uuid,
        'actor_user_uuid' => $guardian->uuid,
        'source_account_uuid' => $guardianAccount->uuid,
        'status' => MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED,
        'provider_name' => 'mtn_momo',
        'recipient_name' => 'Uncle Sibusiso',
        'recipient_msisdn' => '26876123456',
        'amount' => '230.00',
        'asset_code' => 'SZL',
        'note' => 'Support transfer for family emergency',
        'provider_reference_id' => 'provider-ref-1001',
        'mtn_momo_transaction_id' => $mtnTransaction->id,
        'wallet_refunded_at' => null,
        'failed_reason' => 'wallet_refund_failed',
        'idempotency_key' => 'idem-'.Str::lower(Str::random(16)),
    ]);

    MinorFamilyReconciliationException::query()->create([
        'id' => (string) Str::uuid(),
        'mtn_momo_transaction_id' => $mtnTransaction->id,
        'reason_code' => 'unresolved_outcome',
        'status' => MinorFamilyReconciliationException::STATUS_OPEN,
        'source' => 'status_poll',
        'occurrence_count' => 2,
        'metadata' => ['status' => 'failed_unreconciled'],
        'first_seen_at' => now()->subMinutes(3),
        'last_seen_at' => now(),
    ]);

    $mock = \Mockery::mock(MinorFamilyReconciliationService::class);
    $mock->shouldReceive('reconcile')
        ->once()
        ->with(
            \Mockery::on(fn (MtnMomoTransaction $transaction): bool => $transaction->id === $mtnTransaction->id),
            'filament_retry_settlement'
        )
        ->andReturn(MinorFamilyReconciliationOutcome::UNRESOLVED);
    $this->app->instance(MinorFamilyReconciliationService::class, $mock);

    livewire(ListMinorFamilySupportTransfers::class)
        ->assertSuccessful()
        ->assertTableActionVisible('retry_settlement', $supportTransfer)
        ->assertTableActionVisible('view_exception_artifact', $supportTransfer)
        ->callTableAction('retry_settlement', $supportTransfer);

    expect($supportTransfer->fresh()?->status)->toBe(MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED);
});
