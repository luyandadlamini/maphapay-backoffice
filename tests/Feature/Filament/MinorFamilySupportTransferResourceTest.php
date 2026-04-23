<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Filament\Admin\Resources\MinorFamilyFundingAttemptResource\Pages\ListMinorFamilyFundingAttempts;
use App\Filament\Admin\Resources\MinorFamilyFundingAttemptResource\Pages\ViewMinorFamilyFundingAttempt;
use App\Filament\Admin\Resources\MinorFamilySupportTransferResource\Pages\ListMinorFamilySupportTransfers;
use App\Filament\Admin\Resources\MinorFamilySupportTransferResource\Pages\ViewMinorFamilySupportTransfer;
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
