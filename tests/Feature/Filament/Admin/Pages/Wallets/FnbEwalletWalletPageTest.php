<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\WalletLinking;
use App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletLinkedAccountsPage;
use App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletOverviewPage;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line */
    $this->setUpFilamentWithAuth();
});

test('overview renders for admin and shows FNB eWallet', function (): void {
    Livewire::test(FnbEwalletOverviewPage::class)
        ->assertOk()
        ->assertSeeText('FNB eWallet');
});

test('linked accounts table is scoped to fnb_ewallet provider', function (): void {
    $user = User::factory()->create();
    $mineFnb = WalletLinking::factory()->for($user)->create([
        'provider' => 'fnb_ewallet', 'account_ref' => '27761234567',
    ]);
    WalletLinking::factory()->for($user)->create([
        'provider' => 'mtn_momo', 'account_ref' => '46733123453',
    ]);

    Livewire::test(FnbEwalletLinkedAccountsPage::class)
        ->assertCanSeeTableRecords([$mineFnb])
        ->assertCanNotSeeTableRecords(WalletLinking::query()->where('provider', 'mtn_momo')->get());
});
