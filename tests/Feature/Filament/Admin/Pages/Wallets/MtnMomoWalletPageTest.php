<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\WalletLinking;
use App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoLinkedAccountsPage;
use App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoOverviewPage;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line */
    $this->setUpFilamentWithAuth();
});

test('overview renders for admin and shows MTN MoMo', function (): void {
    Livewire::test(MtnMomoOverviewPage::class)
        ->assertOk()
        ->assertSeeText('MTN MoMo');
});

test('linked accounts table is scoped to mtn_momo provider', function (): void {
    $user = User::factory()->create();
    $mineMtn = WalletLinking::factory()->for($user)->create([
        'provider' => 'mtn_momo', 'account_ref' => '46733123453',
    ]);
    WalletLinking::factory()->for($user)->create([
        'provider' => 'fnb_ewallet', 'account_ref' => '99999',
    ]);

    Livewire::test(MtnMomoLinkedAccountsPage::class)
        ->assertCanSeeTableRecords([$mineMtn])
        ->assertCanNotSeeTableRecords(WalletLinking::query()->where('provider', 'fnb_ewallet')->get());
});
