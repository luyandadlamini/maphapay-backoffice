<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_balances', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_uuid');
            $table->string('asset_code', 10);
            $table->bigInteger('balance')->default(0);
            $table->timestamps();

            // Composite unique key
            $table->unique(['account_uuid', 'asset_code']);

            // Foreign keys
            $table->foreign('account_uuid')->references('uuid')->on('accounts')->onDelete('cascade');
            $table->foreign('asset_code')->references('code')->on('assets')->onDelete('restrict');

            // Indexes
            $table->index('asset_code');
            $table->index('balance');
        });

        // Migrate existing account balances to USD
        DB::statement("
            INSERT INTO account_balances (account_uuid, asset_code, balance, created_at, updated_at)
            SELECT uuid, 'USD', balance, created_at, updated_at
            FROM accounts
            WHERE balance > 0
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optionally migrate balances back to accounts table before dropping
        // This is a destructive operation if multiple assets exist
        DB::statement("
            UPDATE accounts a
            SET balance = COALESCE(
                (SELECT balance FROM account_balances ab 
                 WHERE ab.account_uuid = a.uuid 
                 AND ab.asset_code = 'USD'), 
                0
            )
        ");

        Schema::dropIfExists('account_balances');
    }
};
