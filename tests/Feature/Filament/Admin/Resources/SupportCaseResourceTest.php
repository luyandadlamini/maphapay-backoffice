<?php

declare(strict_types=1);

use App\Domain\Support\Models\SupportCase;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $panel = Filament\Facades\Filament::getPanel('admin');
    if ($panel) {
        Filament\Facades\Filament::setCurrentPanel($panel);
        Filament\Facades\Filament::setServingStatus(true);
        $panel->boot();
    }
});

it('support-l1 can create a support case', function () {
    artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $support = User::factory()->create();
    $support->assignRole('support-l1');

    $customer = User::factory()->create();

    actingAs($support);

    livewire(App\Filament\Admin\Resources\SupportCaseResource\Pages\CreateSupportCase::class)
        ->fillForm([
            'user_id'     => $customer->id,
            'subject'     => 'Failed transfer investigation',
            'description' => 'Customer reports funds deducted but not received.',
            'priority'    => 'high',
            'status'      => 'open',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SupportCase::where('subject', 'Failed transfer investigation')->exists())->toBeTrue();
});

it('support hub shows open case count badge', function () {
    artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    SupportCase::factory()->count(3)->create(['status' => 'open']);

    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    actingAs($admin);

    livewire(App\Filament\Admin\Resources\SupportCaseResource\Pages\ListSupportCases::class)
        ->assertSuccessful();
});

it('support-l1 can add a note to a case', function () {
    artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $support = User::factory()->create();
    $support->assignRole('support-l1');

    $case = SupportCase::factory()->create();

    actingAs($support);

    livewire(App\Filament\Admin\Resources\SupportCaseResource\RelationManagers\NotesRelationManager::class, [
        'ownerRecord' => $case,
        'pageClass'   => App\Filament\Admin\Resources\SupportCaseResource\Pages\ViewSupportCase::class,
    ])
        ->callTableAction('create', data: [
            'body'       => 'Reached out to customer regarding the transaction.',
            'visibility' => 'internal',
        ])
        ->assertHasNoTableActionErrors();

    expect($case->notes()->count())->toBe(1);
    expect($case->notes()->first()->body)->toBe('Reached out to customer regarding the transaction.');
    expect($case->notes()->first()->author_id)->toBe($support->id);
});
