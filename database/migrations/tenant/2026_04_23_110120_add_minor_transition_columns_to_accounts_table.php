<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'minor_transition_state')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->string('minor_transition_state')->nullable()->after('permission_level');
            });
        }

        if (! Schema::hasColumn('accounts', 'minor_transition_effective_at')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->timestamp('minor_transition_effective_at')->nullable()->after('minor_transition_state');
            });
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts', 'minor_transition_effective_at')) {
                $table->dropColumn('minor_transition_effective_at');
            }

            if (Schema::hasColumn('accounts', 'minor_transition_state')) {
                $table->dropColumn('minor_transition_state');
            }
        });
    }
};
