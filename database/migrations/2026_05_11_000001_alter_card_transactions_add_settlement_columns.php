<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add settlement lifecycle columns to card_transactions.
 *
 * Phase 6 (webhook handling) requires tracking:
 * - processor_transaction_id: the issuer's external reference (was external_id)
 * - authorization_id: the auth code returned at authorisation time
 * - billing_amount: settled amount (may differ from auth amount for tipping/fx)
 * - refunded_amount: partial or full refund amount
 * - settled_at / reversed_at / refunded_at: timestamps for each state transition
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('card_transactions', function (Blueprint $table): void {
            // Alias for the issuer's transaction reference (external_id is too generic)
            if (!Schema::hasColumn('card_transactions', 'processor_transaction_id')) {
                $table->string('processor_transaction_id')->nullable()->after('user_id');
                $table->index('processor_transaction_id');
            }

            if (!Schema::hasColumn('card_transactions', 'authorization_id')) {
                $table->string('authorization_id')->nullable()->after('processor_transaction_id');
            }

            if (!Schema::hasColumn('card_transactions', 'billing_amount')) {
                $table->string('billing_amount')->nullable()->after('amount_cents');
            }

            if (!Schema::hasColumn('card_transactions', 'refunded_amount')) {
                $table->string('refunded_amount')->nullable()->after('billing_amount');
            }

            if (!Schema::hasColumn('card_transactions', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('transacted_at');
            }

            if (!Schema::hasColumn('card_transactions', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('settled_at');
            }

            if (!Schema::hasColumn('card_transactions', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('reversed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('card_transactions', function (Blueprint $table): void {
            $table->dropIndexIfExists(['processor_transaction_id']);

            foreach (['refunded_at', 'reversed_at', 'settled_at', 'refunded_amount', 'billing_amount', 'authorization_id', 'processor_transaction_id'] as $col) {
                if (Schema::hasColumn('card_transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
