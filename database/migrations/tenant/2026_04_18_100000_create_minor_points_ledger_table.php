<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minor_points_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('minor_account_uuid')->index();
            $table->integer('points'); // positive = earn, negative = deduct
            $table->string('source', 50); // 'saving_milestone'|'level_unlock'|'parent_referral'|'chore'|'redemption'
            $table->string('description');
            $table->string('reference_id', 100)->nullable(); // e.g. '100_szl', chore-uuid, redemption-uuid
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_points_ledger');
    }
};
