<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

afterEach(function (): void {
    Schema::dropIfExists('wallet_provider_transactions');
});

it('uses short index names and can repair a partially created wallet provider transaction table', function (): void {
    Schema::dropIfExists('wallet_provider_transactions');

    Schema::create('wallet_provider_transactions', function ($table): void {
        $table->bigIncrements('id');
        $table->string('provider_id', 64);
        $table->string('provider_request_id', 128);
        $table->string('type', 32);
        $table->string('status', 32);
        $table->string('currency', 8);
        $table->unsignedBigInteger('amount_minor');
        $table->uuid('user_uuid')->nullable();
        $table->json('payload')->nullable();
        $table->timestamp('settled_at')->nullable();
        $table->timestamps();
    });

    $migration = require base_path('database/migrations/2026_05_15_000001_create_wallet_provider_transactions_table.php');

    $migration->up();

    expect(Schema::hasIndex('wallet_provider_transactions', 'wpt_provider_request_unique'))->toBeTrue()
        ->and(Schema::hasIndex('wallet_provider_transactions', 'wpt_provider_status_idx'))->toBeTrue()
        ->and(Schema::hasIndex('wallet_provider_transactions', 'wpt_user_uuid_idx'))->toBeTrue();
});
