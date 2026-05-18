<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Wallets\Emali\EmaliOverviewPage;
use App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletOverviewPage;
use App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoOverviewPage;
use App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyOverviewPage;
use App\Filament\Admin\Pages\Wallets\StandardUnayo\StandardUnayoOverviewPage;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line */
    $this->setUpFilamentWithAuth();
});

dataset('balanceProviderCases', [
    'mtn'     => [MtnMomoOverviewPage::class,          'mtn-momo'],
    'emali'   => [EmaliOverviewPage::class,            'emali'],
    'fnb'     => [FnbEwalletOverviewPage::class,       'fnb-ewallet'],
    'unayo'   => [StandardUnayoOverviewPage::class,    'standard-unayo'],
    'nedbank' => [NedbankSendMoneyOverviewPage::class, 'nedbank-send-money'],
]);

test('balance action calls correct provider endpoint', function (string $pageClass, string $endpointPath): void {
    config()->set('services.mock_wallets.base_url', 'https://internal.test/__mock/wallets');
    config()->set('services.mock_wallets.enabled', true);

    Http::fake([
        "internal.test/__mock/wallets/{$endpointPath}/_admin/balance/46733123453" => Http::response([
            'balance_minor' => 250000,
            'currency'      => 'SZL',
            'last_updated'  => '2026-05-15T12:00:00Z',
        ], 200),
    ]);

    Livewire::test($pageClass)
        ->callAction('balance', [
            'account_ref' => '46733123453',
        ])
        ->assertHasNoActionErrors();

    Http::assertSent(
        fn ($req) => str_ends_with($req->url(), "/{$endpointPath}/_admin/balance/46733123453")
    );
})->with('balanceProviderCases');

test('balance action shows warning notification when mock returns 404', function (): void {
    config()->set('services.mock_wallets.base_url', 'https://internal.test/__mock/wallets');
    config()->set('services.mock_wallets.enabled', true);

    Http::fake([
        'internal.test/__mock/wallets/mtn-momo/_admin/balance/46733123453' => Http::response([], 404),
    ]);

    Livewire::test(MtnMomoOverviewPage::class)
        ->callAction('balance', [
            'account_ref' => '46733123453',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();
});
