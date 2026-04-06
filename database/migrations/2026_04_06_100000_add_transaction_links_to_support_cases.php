<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('support_cases', function (Blueprint $table): void {
            $table->nullableMorphs('linked_subject');
            $table->string('transaction_reference')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('support_cases', function (Blueprint $table): void {
            $table->dropMorphs('linked_subject');
            $table->dropColumn('transaction_reference');
        });
    }
};
