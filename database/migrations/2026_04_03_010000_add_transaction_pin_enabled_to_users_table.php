<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('transaction_pin_enabled')->default(false)->after('transaction_pin');
        });

        DB::table('users')
            ->whereNotNull('transaction_pin')
            ->where('transaction_pin', '!=', '')
            ->update(['transaction_pin_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('transaction_pin_enabled');
        });
    }
};
