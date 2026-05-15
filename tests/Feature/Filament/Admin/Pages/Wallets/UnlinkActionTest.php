<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\WalletLinking;
use App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoLinkedAccountsPage;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    /** @phpstan-ignore-next-line */
    $this->setUpFilamentWithAuth();
});

test('admin can unlink and audit is written', function (): void {
    $user = User::factory()->create();
    $link = WalletLinking::factory()->for($user)->create([
        'provider' => 'mtn_momo', 'link_status' => 'active',
    ]);

    Livewire::test(MtnMomoLinkedAccountsPage::class)
        ->callTableAction('unlink', $link)
        ->assertHasNoTableActionErrors();

    expect($link->fresh()->link_status)->toBe('disabled');
    $this->assertSoftDeleted($link);
    $this->assertDatabaseHas('security_audit_logs', [
        'event_type' => 'wallet.linking_disabled',
        'severity'   => 'high',
        'user_id'    => $this->adminUser?->id,
    ]);
});

test('non admin cannot see unlink action', function (): void {
    $user = User::factory()->create();
    $link = WalletLinking::factory()->for($user)->create(['provider' => 'mtn_momo']);

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(MtnMomoLinkedAccountsPage::class)
        ->assertTableActionHidden('unlink', $link);
});
