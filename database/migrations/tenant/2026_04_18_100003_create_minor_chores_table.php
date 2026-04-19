<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minor_chores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('minor_account_uuid')->index();
            $table->uuid('guardian_account_uuid');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('payout_type', 20)->default('points'); // 'points'|'amount'
            $table->unsignedInteger('payout_points')->default(0);
            $table->decimal('payout_amount', 10, 2)->nullable(); // Phase 5 stub
            $table->timestamp('due_at')->nullable();
            $table->string('recurrence', 20)->nullable(); // 'weekly'|'biweekly'|'monthly'|null
            $table->string('status', 20)->default('active'); // 'active'|'inactive'|'archived'
            $table->timestamps();

            $table->foreign('minor_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');

            $table->foreign('guardian_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_chores');
    }
};
