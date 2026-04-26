<?php

declare(strict_types=1);

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Analytics\WalletRevenueStream;
use App\Filament\Admin\Pages\RevenueStreamsPage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('exposes eleven wallet revenue stream codes from the spec', function (): void {
    expect(WalletRevenueStream::cases())->toHaveCount(11)
        ->and(array_map(static fn (WalletRevenueStream $c): string => $c->value, WalletRevenueStream::cases()))
        ->toBe(
            [
                'p2p_send',
                'request_money',
                'merchant_qr',
                'merchant_pay',
                'topup_momo',
                'cashout',
                'savings_pockets',
                'group_savings',
                'utilities',
                'mcard',
                'rewards',
            ]
        );
});

it('allows finance-lead to access revenue streams page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    expect(RevenueStreamsPage::canAccess())->toBeTrue();

    $this->get(RevenueStreamsPage::getUrl())
        ->assertOk()
        ->assertSee(__('Pending finance mapping'));
});

it('allows super-admin to access revenue streams page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super-admin');
    $this->actingAs($user);

    expect(RevenueStreamsPage::canAccess())->toBeTrue()
        ->and($this->get(RevenueStreamsPage::getUrl())->assertOk());
});

it('forbids support-l1 from accessing revenue streams page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    expect(RevenueStreamsPage::canAccess())->toBeFalse()
        ->and($this->get(RevenueStreamsPage::getUrl())->assertForbidden());
});

it('shows mapped projection activity for p2p when transfer projections exist', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    TransactionProjection::factory()->transfer()->create([
        'created_at' => now(),
        'status'     => 'completed',
    ]);

    $html = $this->get(RevenueStreamsPage::getUrl())->assertOk()->content();

    expect($html)->toContain('data-stream-card="p2p_send"')
        ->and($html)->toContain('data-mapped="1"')
        ->and($html)->toContain(__('Projection activity'));
});
