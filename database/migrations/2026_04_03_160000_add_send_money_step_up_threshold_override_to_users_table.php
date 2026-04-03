<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('send_money_step_up_threshold_override', 20, 2)
                ->nullable()
                ->after('transaction_pin_enabled');
            $table->text('send_money_step_up_threshold_override_reason')
                ->nullable()
                ->after('send_money_step_up_threshold_override');
            $table->timestamp('send_money_step_up_threshold_override_updated_at')
                ->nullable()
                ->after('send_money_step_up_threshold_override_reason');
            $table->string('send_money_step_up_threshold_override_updated_by')
                ->nullable()
                ->after('send_money_step_up_threshold_override_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'send_money_step_up_threshold_override',
                'send_money_step_up_threshold_override_reason',
                'send_money_step_up_threshold_override_updated_at',
                'send_money_step_up_threshold_override_updated_by',
            ]);
        });
    }
};
