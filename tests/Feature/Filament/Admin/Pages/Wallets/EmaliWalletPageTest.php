<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\WalletLinking;
use App\Filament\Admin\Pages\Wallets\Emali\EmaliLinkedAccountsPage;
use App\Filament\Admin\Pages\Wallets\Emali\EmaliOverviewPage;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line */
    $this->setUpFilamentWithAuth();
});

test('overview renders for admin and shows eMali', function (): void {
    Livewire::test(EmaliOverviewPage::class)
        ->assertOk()
        ->assertSeeText('eMali');
});

test('linked accounts table is scoped to emali_eswatini_mobile provider', function (): void {
    $user = User::factory()->create();
    $mineEmali = WalletLinking::factory()->for($user)->create([
        'provider' => 'emali_eswatini_mobile', 'account_ref' => '26876000001',
    ]);
    WalletLinking::factory()->for($user)->create([
        'provider' => 'mtn_momo', 'account_ref' => '46733123453',
    ]);

    Livewire::test(EmaliLinkedAccountsPage::class)
        ->assertCanSeeTableRecords([$mineEmali])
        ->assertCanNotSeeTableRecords(WalletLinking::query()->where('provider', 'mtn_momo')->get());
});
