<?php

declare(strict_types=1);

use App\Domain\Mobile\Models\Pocket;
use App\Domain\Mobile\Models\PocketSmartRule;
use App\Filament\Admin\Resources\PocketResource\Pages\ViewPocket;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('renders the pocket view page when the pocket has no smart rule row', function (): void {
    $admin = User::factory()->withAdminRole()->create();
    $this->actingAs($admin);

    $customer = User::factory()->create();

    $pocket = Pocket::query()->create([
        'user_uuid'      => $customer->uuid,
        'name'           => 'School Fees',
        'target_amount'  => 3500.00,
        'current_amount' => 500.00,
        'category'       => Pocket::CATEGORY_EDUCATION,
        'color'          => '#118AB2',
        'is_completed'   => false,
    ]);

    livewire(ViewPocket::class, ['record' => $pocket->getKey()])
        ->assertSuccessful()
        ->assertSee('School Fees');
});

it('renders the pocket view page when the pocket has a smart rule row', function (): void {
    $admin = User::factory()->withAdminRole()->create();
    $this->actingAs($admin);

    $customer = User::factory()->create();

    $pocket = Pocket::query()->create([
        'user_uuid'      => $customer->uuid,
        'name'           => 'Holiday Fund',
        'target_amount'  => 8000.00,
        'current_amount' => 2000.00,
        'category'       => Pocket::CATEGORY_TRAVEL,
        'color'          => '#4F8CFF',
        'is_completed'   => false,
    ]);

    PocketSmartRule::query()->create([
        'pocket_id'           => $pocket->uuid,
        'round_up_change'     => true,
        'auto_save_deposits'  => false,
        'auto_save_salary'    => true,
        'auto_save_amount'    => 250.00,
        'auto_save_frequency' => PocketSmartRule::FREQUENCY_MONTHLY,
        'lock_pocket'         => false,
        'notify_on_transfer'  => true,
    ]);

    livewire(ViewPocket::class, ['record' => $pocket->getKey()])
        ->assertSuccessful()
        ->assertSee('Holiday Fund');
});
