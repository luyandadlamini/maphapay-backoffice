<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // Amount (SZL) the guardian pre-sets as a reserve for emergencies.
            // Null means emergency allowance is disabled.
            $table->unsignedInteger('emergency_allowance_amount')
                ->nullable()
                ->default(null)
                ->after('permission_level');

            // Remaining balance of the emergency reserve.
            // Refilled to emergency_allowance_amount when guardian resets it.
            $table->unsignedInteger('emergency_allowance_balance')
                ->default(0)
                ->after('emergency_allowance_amount');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['emergency_allowance_amount', 'emergency_allowance_balance']);
        });
    }
};
