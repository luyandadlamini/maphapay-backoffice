<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Filament\Admin\Resources\MinorFamilyFundingLinkResource\Pages\ListMinorFamilyFundingLinks;
use App\Filament\Admin\Resources\MinorFamilyFundingLinkResource\Pages\ViewMinorFamilyFundingLink;
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

it('allows authorized operators to list and view funding links', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('finance-lead');
    $this->actingAs($operator);

    $minorOwner = User::factory()->create();
    $creator = User::factory()->create();

    $minorAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Minor Wallet',
        'user_uuid' => $minorOwner->uuid,
    ]);

    $creatorAccount = Account::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Guardian Wallet',
        'user_uuid' => $creator->uuid,
    ]);

    $fundingLink = MinorFamilyFundingLink::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'tenant-filament-tests',
        'minor_account_uuid' => $minorAccount->uuid,
        'created_by_user_uuid' => $creator->uuid,
        'created_by_account_uuid' => $creatorAccount->uuid,
        'title' => 'School transport support',
        'note' => 'Family contribution for school transport',
        'token' => 'minor-link-'.Str::lower(Str::random(16)),
        'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
        'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
        'fixed_amount' => null,
        'target_amount' => '500.00',
        'collected_amount' => '150.00',
        'asset_code' => 'SZL',
        'provider_options' => [MinorFamilyFundingLink::DEFAULT_PROVIDER],
        'expires_at' => now()->addDays(5),
    ]);

    livewire(ListMinorFamilyFundingLinks::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$fundingLink]);

    livewire(ViewMinorFamilyFundingLink::class, ['record' => $fundingLink->getKey()])
        ->assertSuccessful();
});

it('keeps funding link list actions conservative with no provider status shortcuts', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole('finance-lead');
    $this->actingAs($operator);

    livewire(ListMinorFamilyFundingLinks::class)
        ->assertSuccessful()
        ->assertTableActionDoesNotExist('retry')
        ->assertTableActionDoesNotExist('refund');
});
