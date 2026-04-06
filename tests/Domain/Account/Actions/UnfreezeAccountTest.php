<?php

declare(strict_types=1);

use App\Domain\Account\Actions\UnfreezeAccount;
use App\Domain\Account\Events\AccountUnfrozen;
use App\Domain\Account\Models\Account;

it('can unfreeze an account', function () {
    $account = Account::factory()->create(['frozen' => true]);

    $event = Mockery::mock(AccountUnfrozen::class);
    $event->shouldReceive('aggregateRootUuid')
        ->andReturn($account->uuid);

    $action = new UnfreezeAccount();
    $action($event);

    $account->refresh();
    expect($account->frozen)->toBeFalse();
});

it('throws exception when account not found', function () {
    $event = Mockery::mock(AccountUnfrozen::class);
    $event->shouldReceive('aggregateRootUuid')
        ->andReturn('non-existent-uuid');

    $action = new UnfreezeAccount();

    expect(fn () => $action($event))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('can be invoked', function () {
    expect(is_callable(new UnfreezeAccount()))->toBeTrue();
});
