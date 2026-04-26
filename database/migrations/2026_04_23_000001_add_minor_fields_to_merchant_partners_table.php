<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('merchant_partners', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->decimal('bonus_multiplier', 3, 2)->default(2.0)->after('tenant_id');
            $table->smallInteger('min_age_allowance')->default(0)->after('bonus_multiplier');
            $table->json('category_slugs')->nullable()->after('min_age_allowance');
            $table->boolean('is_active_for_minors')->default(true)->after('category_slugs');
            $table->text('bonus_terms')->nullable()->after('is_active_for_minors');
            $table->uuid('updated_by')->nullable()->after('bonus_terms');

            $table->index('tenant_id');
            $table->index('is_active_for_minors');
            $table->index('min_age_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_partners', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropIndex(['is_active_for_minors']);
            $table->dropIndex(['min_age_allowance']);
            $table->dropColumn([
                'tenant_id',
                'bonus_multiplier',
                'min_age_allowance',
                'category_slugs',
                'is_active_for_minors',
                'bonus_terms',
                'updated_by',
            ]);
        });
    }
};
