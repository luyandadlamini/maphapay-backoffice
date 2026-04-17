<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('anomaly_detections', function (Blueprint $table) {
            $table->string('triage_status')->default('detected')->after('status');
            // detected | under_review | escalated | resolved | false_positive
            $table->unsignedBigInteger('assigned_to')->nullable()->after('triage_status');
            $table->unsignedBigInteger('resolved_by')->nullable()->after('assigned_to');
            $table->text('resolution_notes')->nullable()->after('resolved_by');
            $table->string('resolution_type')->nullable()->after('resolution_notes');
            // fraud | false_positive | low_risk
            $table->timestamp('resolved_at')->nullable()->after('resolution_type');

            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->index('triage_status');
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('anomaly_detections', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropForeign(['resolved_by']);
            $table->dropColumn([
                'triage_status', 'assigned_to', 'resolved_by',
                'resolution_notes', 'resolution_type', 'resolved_at',
            ]);
        });
    }
};
