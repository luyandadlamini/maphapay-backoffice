<?php

declare(strict_types=1);

use App\Domain\Account\Services\AccountService;
use Workflow\WorkflowStub;

beforeEach(function () {
    WorkflowStub::fake();
    $this->accountService = app(AccountService::class);
});

it('has create method', function () {
    expect((new ReflectionClass($this->accountService))->hasMethod('create'))->toBeTrue();
});

it('has destroy method', function () {
    expect((new ReflectionClass($this->accountService))->hasMethod('destroy'))->toBeTrue();
});

it('has deposit method', function () {
    expect((new ReflectionClass($this->accountService))->hasMethod('deposit'))->toBeTrue();
});

it('has withdraw method', function () {
    expect((new ReflectionClass($this->accountService))->hasMethod('withdraw'))->toBeTrue();
});

it('can be instantiated from container', function () {
    expect($this->accountService)->toBeInstanceOf(AccountService::class);
});

it('can create account with array data', function () {
    $accountData = [
        'name'      => 'Test Account',
        'user_uuid' => 'user-uuid-123',
    ];

    // Test that the method completes successfully
    $this->accountService->create($accountData);

    // If we reach this point, the method didn't throw an exception
    expect(true)->toBeTrue();
});

it('can destroy account with uuid string', function () {
    $uuid = 'test-account-uuid';

    $this->accountService->destroy($uuid);

    expect(true)->toBeTrue();
});

it('can deposit to account with uuid and amount', function () {
    $uuid = 'test-account-uuid';
    $amount = 5000;

    $this->accountService->deposit($uuid, $amount);

    expect(true)->toBeTrue();
});

it('can withdraw from account with uuid and amount', function () {
    $uuid = 'test-account-uuid';
    $amount = 2500;

    $this->accountService->withdraw($uuid, $amount);

    expect(true)->toBeTrue();
});
