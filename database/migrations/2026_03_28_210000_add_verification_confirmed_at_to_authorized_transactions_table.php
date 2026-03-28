<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('authorized_transactions', function (Blueprint $table): void {
            $table->timestamp('verification_confirmed_at')->nullable()->after('otp_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('authorized_transactions', function (Blueprint $table): void {
            $table->dropColumn('verification_confirmed_at');
        });
    }
};
