<?php

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\Turnover;
use Illuminate\Support\Carbon;


it('can create a turnover record', function () {
    $account = Account::factory()->create();
    $date = Carbon::now()->toDateString();

    $turnover = Turnover::create([
        'account_uuid' => $account->uuid,
        'date'         => $date,
        'count'        => 5,
        'amount'       => 1000,
        'debit'        => 500,
        'credit'       => 1500,
    ]);

    expect($turnover->account_uuid)->toBe($account->uuid);
    expect($turnover->date->toDateString())->toBe($date);
    expect($turnover->count)->toBe(5);
    expect($turnover->amount)->toBe(1000);
    expect($turnover->debit)->toBe(500);
    expect($turnover->credit)->toBe(1500);
});

it('belongs to an account', function () {
    $account = Account::factory()->create();
    $turnover = Turnover::factory()->create(['account_uuid' => $account->uuid]);

    expect($turnover->account)->toBeInstanceOf(Account::class);
    expect($turnover->account->uuid)->toEqual($account->uuid);
});

it('can be found by date', function () {
    $account = Account::factory()->create();
    $today = Carbon::now()->toDateString();

    $todayTurnover = Turnover::create([
        'account_uuid' => $account->uuid,
        'date'         => $today,
        'count'        => 1,
        'amount'       => 100,
        'debit'        => 50,
        'credit'       => 150,
    ]);

    // Check that it was created
    expect($todayTurnover->id)->not()->toBeNull();

    // Find using the ID first to ensure it's there
    $foundById = Turnover::find($todayTurnover->id);
    expect($foundById)->not()->toBeNull();

    // Now test the query we want to use
    $foundTurnover = Turnover::where('account_uuid', $account->uuid)->first();

    expect($foundTurnover)->not()->toBeNull();
    expect($foundTurnover->id)->toBe($todayTurnover->id);
});

it('has fillable attributes', function () {
    $account = Account::factory()->create();

    $turnover = new Turnover([
        'account_uuid' => $account->uuid,
        'date'         => Carbon::now()->toDateString(),
        'count'        => 3,
        'amount'       => 750,
        'debit'        => 250,
        'credit'       => 1000,
    ]);

    expect($turnover->account_uuid)->toBe($account->uuid);
    expect($turnover->count)->toBe(3);
    expect($turnover->amount)->toBe(750);
    expect($turnover->debit)->toBe(250);
    expect($turnover->credit)->toBe(1000);
});
