<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Models\User;

beforeEach(function () {
    $this->seed();
});

it('cancels expired pending approvals', function () {
    // Create users and accounts
    $guardian = User::factory()->create();
    $guardianAccount = Account::factory()->create(['user_uuid' => $guardian->uuid]);

    $minor = User::factory()->create();
    $minorAccount = Account::factory()->create(['user_uuid' => $minor->uuid]);

    $fromAccount = Account::factory()->create();
    $toAccount = Account::factory()->create();

    // Create pending approval that expired 1 hour ago
    $expiredApproval = MinorSpendApproval::create([
        'minor_account_uuid'    => $minorAccount->uuid,
        'guardian_account_uuid' => $guardianAccount->uuid,
        'from_account_uuid'     => $fromAccount->uuid,
        'to_account_uuid'       => $toAccount->uuid,
        'amount'                => '100.00',
        'asset_code'            => 'USD',
        'merchant_category'     => 'groceries',
        'status'                => 'pending',
        'expires_at'            => now()->subHour(),
        'decided_at'            => null,
    ]);

    // Create pending approval that hasn't expired yet
    $validApproval = MinorSpendApproval::create([
        'minor_account_uuid'    => $minorAccount->uuid,
        'guardian_account_uuid' => $guardianAccount->uuid,
        'from_account_uuid'     => $fromAccount->uuid,
        'to_account_uuid'       => $toAccount->uuid,
        'amount'                => '50.00',
        'asset_code'            => 'USD',
        'merchant_category'     => 'entertainment',
        'status'                => 'pending',
        'expires_at'            => now()->addHour(),
        'decided_at'            => null,
    ]);

    // Run the command
    $this->artisan('minor-accounts:expire-approvals')
        ->assertSuccessful();

    // Verify expired approval was cancelled
    $expiredApproval->refresh();
    expect($expiredApproval->status)->toBe('cancelled');
    expect($expiredApproval->decided_at)->not->toBeNull();
    expect($expiredApproval->decided_at->isSameDay(now()))->toBeTrue();

    // Verify valid approval is still pending
    $validApproval->refresh();
    expect($validApproval->status)->toBe('pending');
    expect($validApproval->decided_at)->toBeNull();
});

it('does not touch already decided approvals', function () {
    // Create users and accounts
    $guardian = User::factory()->create();
    $guardianAccount = Account::factory()->create(['user_uuid' => $guardian->uuid]);

    $minor = User::factory()->create();
    $minorAccount = Account::factory()->create(['user_uuid' => $minor->uuid]);

    $fromAccount = Account::factory()->create();
    $toAccount = Account::factory()->create();

    // Create already-approved approval that has expired
    $approvedApproval = MinorSpendApproval::create([
        'minor_account_uuid'    => $minorAccount->uuid,
        'guardian_account_uuid' => $guardianAccount->uuid,
        'from_account_uuid'     => $fromAccount->uuid,
        'to_account_uuid'       => $toAccount->uuid,
        'amount'                => '100.00',
        'asset_code'            => 'USD',
        'merchant_category'     => 'groceries',
        'status'                => 'approved',
        'expires_at'            => now()->subHour(),
        'decided_at'            => now()->subHour(),
    ]);

    // Create already-declined approval that has expired
    $declinedApproval = MinorSpendApproval::create([
        'minor_account_uuid'    => $minorAccount->uuid,
        'guardian_account_uuid' => $guardianAccount->uuid,
        'from_account_uuid'     => $fromAccount->uuid,
        'to_account_uuid'       => $toAccount->uuid,
        'amount'                => '75.00',
        'asset_code'            => 'USD',
        'merchant_category'     => 'dining',
        'status'                => 'declined',
        'expires_at'            => now()->subHour(),
        'decided_at'            => now()->subHour(),
    ]);

    // Run the command
    $this->artisan('minor-accounts:expire-approvals')
        ->assertSuccessful();

    // Verify approvals remain unchanged
    $approvedApproval->refresh();
    expect($approvedApproval->status)->toBe('approved');

    $declinedApproval->refresh();
    expect($declinedApproval->status)->toBe('declined');
});

it('is idempotent', function () {
    // Create users and accounts
    $guardian = User::factory()->create();
    $guardianAccount = Account::factory()->create(['user_uuid' => $guardian->uuid]);

    $minor = User::factory()->create();
    $minorAccount = Account::factory()->create(['user_uuid' => $minor->uuid]);

    $fromAccount = Account::factory()->create();
    $toAccount = Account::factory()->create();

    // Create pending approval that expired 1 hour ago
    $expiredApproval = MinorSpendApproval::create([
        'minor_account_uuid'    => $minorAccount->uuid,
        'guardian_account_uuid' => $guardianAccount->uuid,
        'from_account_uuid'     => $fromAccount->uuid,
        'to_account_uuid'       => $toAccount->uuid,
        'amount'                => '100.00',
        'asset_code'            => 'USD',
        'merchant_category'     => 'groceries',
        'status'                => 'pending',
        'expires_at'            => now()->subHour(),
        'decided_at'            => null,
    ]);

    // Run the command first time
    $this->artisan('minor-accounts:expire-approvals')
        ->assertSuccessful();

    $expiredApproval->refresh();
    $firstRunDecidedAt = $expiredApproval->decided_at;

    // Run the command again
    $this->artisan('minor-accounts:expire-approvals')
        ->assertSuccessful();

    $expiredApproval->refresh();

    // Verify the approval is still cancelled and decided_at hasn't changed
    expect($expiredApproval->status)->toBe('cancelled');
    expect($expiredApproval->decided_at->eq($firstRunDecidedAt))->toBeTrue();
});
