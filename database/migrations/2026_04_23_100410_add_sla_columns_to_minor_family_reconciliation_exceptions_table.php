<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('minor_family_reconciliation_exceptions', function (Blueprint $table): void {
            $table->timestamp('sla_due_at')->nullable()->after('last_seen_at');
            $table->timestamp('sla_escalated_at')->nullable()->after('sla_due_at');

            $table->index(['status', 'sla_due_at'], 'minor_family_recon_exceptions_status_sla_due_index');
            $table->index(['status', 'sla_escalated_at'], 'minor_family_recon_exceptions_status_sla_esc_index');
        });
    }

    public function down(): void
    {
        Schema::table('minor_family_reconciliation_exceptions', function (Blueprint $table): void {
            $table->dropIndex('minor_family_recon_exceptions_status_sla_due_index');
            $table->dropIndex('minor_family_recon_exceptions_status_sla_esc_index');
            $table->dropColumn(['sla_due_at', 'sla_escalated_at']);
        });
    }
};
