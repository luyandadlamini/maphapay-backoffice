<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Wallets\Emali\EmaliOverviewPage;
use App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletOverviewPage;
use App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoOverviewPage;
use App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyOverviewPage;
use App\Filament\Admin\Pages\Wallets\StandardUnayo\StandardUnayoOverviewPage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line */
    $this->setUpFilamentWithAuth();
});

dataset('providerCases', [
    'mtn'     => [MtnMomoOverviewPage::class,          'mtn-momo'],
    'emali'   => [EmaliOverviewPage::class,            'emali'],
    'fnb'     => [FnbEwalletOverviewPage::class,       'fnb-ewallet'],
    'unayo'   => [StandardUnayoOverviewPage::class,    'standard-unayo'],
    'nedbank' => [NedbankSendMoneyOverviewPage::class, 'nedbank-send-money'],
]);

test('fund action posts to correct provider endpoint', function (string $pageClass, string $endpointPath): void {
    config()->set('services.mock_wallets.base_url', 'https://internal.test/__mock/wallets');
    config()->set('services.mock_wallets.enabled', true);

    Http::fake([
        "internal.test/__mock/wallets/{$endpointPath}/_admin/fund" => Http::response([
            'balance_minor' => 100000,
            'currency'      => 'SZL',
        ], 200),
    ]);

    Livewire::test($pageClass)
        ->callAction('fund', [
            'account_ref'  => '46733123453',
            'amount_major' => 100,
            'note'         => 'pest',
        ])
        ->assertHasNoActionErrors();

    Http::assertSent(
        fn ($req) => str_ends_with($req->url(), "/{$endpointPath}/_admin/fund")
        && $req['account_ref'] === '46733123453'
        && $req['amount'] === 10000
    );

    $this->assertDatabaseHas('security_audit_logs', [
        'event_type' => 'wallet.mock_fund',
        'severity'   => 'medium',
        'user_id'    => $this->adminUser?->id,
    ]);
})->with('providerCases');

test('non admin cannot see fund action', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(MtnMomoOverviewPage::class)
        ->assertActionHidden('fund');
});
