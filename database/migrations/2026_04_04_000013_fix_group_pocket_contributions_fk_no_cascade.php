<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjust the FK constraints so that the ThreadGroupSavingsObserver can zero
 * contribution amounts before the thread is removed:
 *
 *  • group_pockets.thread_id         → SET NULL on delete (pocket rows survive, thread_id becomes null)
 *  • group_pocket_contributions.group_pocket_id → CASCADE (unchanged, contributions deleted with pocket)
 *
 * With this schema the observer zeros contributions during Thread.deleting,
 * then the thread hard-delete fires:
 *   - MySQL NULLs the thread_id on pocket rows  (pockets survive)
 *   - Contributions still exist (CASCADE only fires when a pocket is deleted)
 *   - Test assertDatabaseHas can find contributions with amount=0.00
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('group_pockets', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            $table->unsignedBigInteger('thread_id')->nullable()->change();
            $table->foreign('thread_id')
                ->references('id')
                ->on('threads')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('group_pockets', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            // Cannot safely revert NULL rows to NOT NULL without data loss; best-effort
            $table->foreignId('thread_id')->constrained('threads')->cascadeOnDelete()->change();
        });
    }
};
