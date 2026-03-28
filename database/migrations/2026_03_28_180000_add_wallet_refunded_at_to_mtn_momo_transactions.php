<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('mtn_momo_transactions', function (Blueprint $table) {
            $table->timestamp('wallet_refunded_at')->nullable()->after('wallet_debited_at');
        });
    }

    public function down(): void
    {
        Schema::table('mtn_momo_transactions', function (Blueprint $table) {
            $table->dropColumn('wallet_refunded_at');
        });
    }
};
