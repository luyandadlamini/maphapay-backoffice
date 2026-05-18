<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('wallet_provider_transactions')) {
            Schema::create('wallet_provider_transactions', function (Blueprint $table) {
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
        }

        Schema::table('wallet_provider_transactions', function (Blueprint $table) {
            if (! Schema::hasIndex('wallet_provider_transactions', 'wpt_provider_request_unique')) {
                $table->unique(['provider_id', 'provider_request_id'], 'wpt_provider_request_unique');
            }

            if (! Schema::hasIndex('wallet_provider_transactions', 'wpt_provider_status_idx')) {
                $table->index(['provider_id', 'status'], 'wpt_provider_status_idx');
            }

            if (! Schema::hasIndex('wallet_provider_transactions', 'wpt_user_uuid_idx')) {
                $table->index('user_uuid', 'wpt_user_uuid_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_provider_transactions');
    }
};
