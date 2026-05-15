<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\WalletLinking;
use App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyLinkedAccountsPage;
use App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyOverviewPage;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line */
    $this->setUpFilamentWithAuth();
});

test('overview renders for admin and shows Nedbank Send Money', function (): void {
    Livewire::test(NedbankSendMoneyOverviewPage::class)
        ->assertOk()
        ->assertSeeText('Nedbank Send Money');
});

test('linked accounts table is scoped to nedbank_send_money provider', function (): void {
    $user = User::factory()->create();
    $mineNedbank = WalletLinking::factory()->for($user)->create([
        'provider' => 'nedbank_send_money', 'account_ref' => '27811234567',
    ]);
    WalletLinking::factory()->for($user)->create([
        'provider' => 'mtn_momo', 'account_ref' => '46733123453',
    ]);

    Livewire::test(NedbankSendMoneyLinkedAccountsPage::class)
        ->assertCanSeeTableRecords([$mineNedbank])
        ->assertCanNotSeeTableRecords(WalletLinking::query()->where('provider', 'mtn_momo')->get());
});
