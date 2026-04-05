<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Models\MultiSigWallet;
use App\GraphQL\DataLoaders\AccountDataLoader;
use App\GraphQL\DataLoaders\WalletDataLoader;


describe('GraphQL DataLoaders', function () {
    it('batch loads accounts by id', function () {
        $accounts = Account::factory()->count(3)->create();
        $ids = $accounts->pluck('id')->toArray();

        $loader = new AccountDataLoader();
        $result = $loader->resolve($ids);

        expect($result)->toHaveCount(3);
        foreach ($ids as $id) {
            expect($result->has($id))->toBeTrue();
        }
    });

    it('batch loads accounts by uuid', function () {
        $accounts = Account::factory()->count(2)->create();
        $uuids = $accounts->pluck('uuid')->toArray();

        $loader = new AccountDataLoader();
        $result = $loader->resolveByUuid($uuids);

        expect($result)->toHaveCount(2);
        foreach ($uuids as $uuid) {
            expect($result->has($uuid))->toBeTrue();
        }
    });

    it('batch loads wallets by id', function () {
        $user = App\Models\User::factory()->create();
        $walletIds = [];
        for ($i = 0; $i < 3; $i++) {
            $wallet = MultiSigWallet::create([
                'user_id'             => $user->id,
                'name'                => "Wallet {$i}",
                'chain'               => 'ethereum',
                'required_signatures' => 2,
                'total_signers'       => 3,
                'status'              => 'active',
            ]);
            $walletIds[] = $wallet->id;
        }

        $loader = new WalletDataLoader();
        $result = $loader->resolve($walletIds);

        expect($result)->toHaveCount(3);
        foreach ($walletIds as $id) {
            expect($result->has($id))->toBeTrue();
        }
    });
});
