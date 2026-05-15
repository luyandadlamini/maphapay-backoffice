<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\WalletLinking;
use App\Filament\Admin\Pages\Wallets\StandardUnayo\StandardUnayoLinkedAccountsPage;
use App\Filament\Admin\Pages\Wallets\StandardUnayo\StandardUnayoOverviewPage;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line */
    $this->setUpFilamentWithAuth();
});

test('overview renders for admin and shows Standard Unayo', function (): void {
    Livewire::test(StandardUnayoOverviewPage::class)
        ->assertOk()
        ->assertSeeText('Standard Unayo');
});

test('linked accounts table is scoped to standard_unayo provider', function (): void {
    $user = User::factory()->create();
    $mineUnayo = WalletLinking::factory()->for($user)->create([
        'provider' => 'standard_unayo', 'account_ref' => '26876111111',
    ]);
    WalletLinking::factory()->for($user)->create([
        'provider' => 'mtn_momo', 'account_ref' => '46733123453',
    ]);

    Livewire::test(StandardUnayoLinkedAccountsPage::class)
        ->assertCanSeeTableRecords([$mineUnayo])
        ->assertCanNotSeeTableRecords(WalletLinking::query()->where('provider', 'mtn_momo')->get());
});
