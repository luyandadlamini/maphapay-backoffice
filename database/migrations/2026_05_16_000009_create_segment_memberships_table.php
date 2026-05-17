<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('segment_memberships', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('segment_id');
            $table->timestamp('joined_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('materialised_at');
            $table->timestamps();

            $table->foreign('segment_id')->references('id')->on('customer_segments')->onDelete('cascade');
            $table->index('user_id');
            $table->unique(['user_id', 'segment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segment_memberships');
    }
};
