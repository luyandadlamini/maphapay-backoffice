<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('user_uuid')->nullable(false)->change();

            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('accounts_user');
            }
            $table->foreign('user_uuid', 'accounts_user')
                ->references('uuid')
                ->on('users')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('user_uuid')->nullable()->change();
        });
    }
};
