<?php

use App\Domain\Account\Models\Account;
use App\Domain\Banking\Models\BankAccountModel;
use App\Filament\Admin\Resources\AccountResource\Pages\ViewAccount;
use App\Filament\Admin\Resources\AccountResource\RelationManagers\LinkedWalletsRelationManager;
use App\Models\User;
use function Pest\Livewire\livewire;
use Filament\Facades\Filament;

function setupFilamentPanelForWallets(): void
{
    $panel = Filament::getPanel('admin');
    if ($panel) {
        Filament::setCurrentPanel($panel);
        Filament::setServingStatus(true);
        $panel->boot();
    }
}

it('can display linked wallets relation manager on view account page', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $admin = User::factory()->withAdminRole()->create();
    $this->actingAs($admin);
    setupFilamentPanelForWallets();

    $user = User::factory()->create();
    $account = \App\Domain\Account\Models\Account::factory()->create(['user_uuid' => $user->uuid]);

    $wallet = BankAccountModel::factory()->create([
        'user_uuid' => $user->uuid,
        'status' => 'active',
        'account_number' => encrypt('8001234567'),
    ]);

    livewire(LinkedWalletsRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewAccount::class,
    ])
    ->assertSuccessful()
    ->assertCanSeeTableRecords([$wallet]);
});

it('can unlink a linked wallet with a required reason', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $admin = User::factory()->withAdminRole()->create();
    $this->actingAs($admin);
    setupFilamentPanelForWallets();

    $user = User::factory()->create();
    $account = Account::factory()->create(['user_uuid' => $user->uuid]);

    $wallet = BankAccountModel::factory()->create([
        'user_uuid' => $user->uuid,
        'status' => 'active',
        'account_number' => encrypt('8001234567'),
    ]);

    livewire(LinkedWalletsRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewAccount::class,
    ])
    ->callTableAction('unlink', $wallet, ['reason' => 'Customer requested unlinking due to changed number.'])
    ->assertHasNoTableActionErrors();

    expect($wallet->fresh()->status)->toBe('inactive');
    expect($wallet->fresh()->metadata['unlinked_reason'])->toBe('Customer requested unlinking due to changed number.');
});
