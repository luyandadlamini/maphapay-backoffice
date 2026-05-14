<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_cardholder_id')->nullable()->after('id');
            $table->index('stripe_cardholder_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['stripe_cardholder_id']);
            $table->dropColumn('stripe_cardholder_id');
        });
    }
};
