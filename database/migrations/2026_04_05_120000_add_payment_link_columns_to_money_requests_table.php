<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('money_requests', function (Blueprint $table) {
            $table->string('payment_token', 32)->nullable()->unique()->after('trx');
            $table->timestamp('expires_at')->nullable()->after('payment_token');
            $table->timestamp('paid_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('money_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_token', 'expires_at', 'paid_at']);
        });
    }
};
