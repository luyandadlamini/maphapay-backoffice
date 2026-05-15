<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('wallet_linkings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('account_ref', 64);
            $table->string('currency', 8)->default('SZL');
            $table->string('link_status', 16); // active|pending|failed|disabled
            $table->timestamp('linked_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'provider', 'account_ref'], 'wallet_linkings_user_provider_ref_unique');
            $table->index(['provider', 'link_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_linkings');
    }
};
