<?php

use App\Filament\Admin\Resources\MtnMomoTransactionResource\Pages\ListMtnMomoTransactions;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use function Pest\Livewire\livewire;
use Filament\Facades\Filament;

function setupFilamentPanel(): void
{
    $panel = Filament::getPanel('admin');
    if ($panel) {
        Filament::setCurrentPanel($panel);
        Filament::setServingStatus(true);
        $panel->boot();
    }
}

function createTransaction(User $user, string $status, string $type)
{
    $transaction = new MtnMomoTransaction();
    $transaction->id = (string) \Illuminate\Support\Str::uuid();
    $transaction->user_id = $user->id;
    $transaction->idempotency_key = (string) \Illuminate\Support\Str::uuid();
    $transaction->type = $type;
    $transaction->amount = '1000';
    $transaction->currency = 'SZL';
    $transaction->party_msisdn = '26876123456';
    $transaction->status = $status;
    $transaction->save();

    return $transaction;
}

it('displays retry action for failed disbursements to finance lead', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);
    setupFilamentPanel();

    $transaction = createTransaction($finance, MtnMomoTransaction::STATUS_FAILED, MtnMomoTransaction::TYPE_DISBURSEMENT);

    livewire(ListMtnMomoTransactions::class)
        ->assertTableActionVisible('retry', $transaction)
        ->assertTableActionHidden('refund', $transaction)
        ->callTableAction('retry', $transaction);

    expect($transaction->fresh()->status)->toBe(MtnMomoTransaction::STATUS_PENDING);
});

it('displays refund action for failed collections to finance lead', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);
    setupFilamentPanel();

    $transaction = createTransaction($finance, MtnMomoTransaction::STATUS_FAILED, MtnMomoTransaction::TYPE_REQUEST_TO_PAY);

    livewire(ListMtnMomoTransactions::class)
        ->assertTableActionVisible('refund', $transaction)
        ->assertTableActionHidden('retry', $transaction)
        ->callTableAction('refund', $transaction);

    expect($transaction->fresh()->status)->toBe(MtnMomoTransaction::STATUS_SUCCESSFUL);
});

it('hides retry and refund actions from support-l1', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);
    setupFilamentPanel();

    $disbursement = createTransaction($support, MtnMomoTransaction::STATUS_FAILED, MtnMomoTransaction::TYPE_DISBURSEMENT);
    $collection = createTransaction($support, MtnMomoTransaction::STATUS_FAILED, MtnMomoTransaction::TYPE_REQUEST_TO_PAY);

    livewire(ListMtnMomoTransactions::class)
        ->assertTableActionHidden('retry', $disbursement)
        ->assertTableActionHidden('refund', $collection);
});
