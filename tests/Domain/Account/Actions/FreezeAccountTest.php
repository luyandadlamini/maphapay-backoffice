<?php

declare(strict_types=1);

use App\Domain\Account\Actions\FreezeAccount;
use App\Domain\Account\Events\AccountFrozen;
use App\Domain\Account\Models\Account;

it('can freeze an account', function () {
    $account = Account::factory()->create(['frozen' => false]);

    $event = Mockery::mock(AccountFrozen::class);
    $event->shouldReceive('aggregateRootUuid')
        ->andReturn($account->uuid);

    $action = new FreezeAccount();
    $action($event);

    $account->refresh();
    expect($account->frozen)->toBeTrue();
});

it('throws exception when account not found', function () {
    $event = Mockery::mock(AccountFrozen::class);
    $event->shouldReceive('aggregateRootUuid')
        ->andReturn('non-existent-uuid');

    $action = new FreezeAccount();

    expect(fn () => $action($event))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('can be invoked', function () {
    expect(is_callable(new FreezeAccount()))->toBeTrue();
});
