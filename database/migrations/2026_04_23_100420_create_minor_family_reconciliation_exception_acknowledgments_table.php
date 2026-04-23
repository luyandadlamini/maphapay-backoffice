<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_family_reconciliation_exception_acknowledgments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('minor_family_reconciliation_exception_id');
            $table->uuid('acknowledged_by_user_uuid');
            $table->text('note');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('minor_family_reconciliation_exception_id', 'mf_recon_ack_exception_fk')
                ->references('id')
                ->on('minor_family_reconciliation_exceptions')
                ->cascadeOnDelete();

            $table->index(
                'minor_family_reconciliation_exception_id',
                'mf_recon_ack_exception_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_family_reconciliation_exception_acknowledgments');
    }
};
