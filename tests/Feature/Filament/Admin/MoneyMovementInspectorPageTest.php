<?php

declare(strict_types=1);

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Filament\Admin\Pages\MoneyMovementInspector;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->setUpFilamentWithAuth();

    Asset::firstOrCreate(
        ['code' => 'SZL'],
        [
            'name'      => 'Swazi Lilangeni',
            'type'      => 'fiat',
            'precision' => 2,
            'is_active' => true,
        ],
    );
});

test('money movement inspector resolves a transaction by trx', function (): void {
    $user = User::factory()->create(['kyc_status' => 'approved']);

    $txn = AuthorizedTransaction::query()->create([
        'user_id' => $user->id,
        'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
        'trx'     => 'TRX-INSPECT-0001',
        'payload' => [
            'amount'     => '10.00',
            'asset_code' => 'SZL',
        ],
        'status' => AuthorizedTransaction::STATUS_COMPLETED,
        'result' => [
            'trx'        => 'TRX-INSPECT-0001',
            'reference'  => 'REF-INSPECT-0001',
            'amount'     => '10.00',
            'asset_code' => 'SZL',
        ],
        'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        'expires_at'        => now()->addHour(),
    ]);

    Livewire::test(MoneyMovementInspector::class)
        ->set('data.lookupType', 'trx')
        ->set('data.lookupValue', $txn->trx)
        ->call('inspect')
        ->assertSet('inspection.lookup.trx', $txn->trx)
        ->assertSee($txn->trx)
        ->assertSee('REF-INSPECT-0001');
});
