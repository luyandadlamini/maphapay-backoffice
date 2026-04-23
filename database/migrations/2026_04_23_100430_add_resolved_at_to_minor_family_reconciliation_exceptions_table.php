<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('minor_family_reconciliation_exceptions', function (Blueprint $table): void {
            $table->timestamp('resolved_at')->nullable()->after('sla_escalated_at');
            $table->index(['status', 'resolved_at'], 'minor_family_recon_exceptions_status_resolved_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('minor_family_reconciliation_exceptions', function (Blueprint $table): void {
            $table->dropIndex('minor_family_recon_exceptions_status_resolved_at_index');
            $table->dropColumn('resolved_at');
        });
    }
};
