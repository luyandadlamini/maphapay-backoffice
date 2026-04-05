<?php

declare(strict_types=1);

use App\Models\MoneyRequest;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        MoneyRequest::query()
            ->where('status', MoneyRequest::STATUS_AWAITING_OTP)
            ->update(['status' => MoneyRequest::STATUS_PENDING]);
    }

    public function down(): void
    {
        // Irreversible data backfill.
    }
};
