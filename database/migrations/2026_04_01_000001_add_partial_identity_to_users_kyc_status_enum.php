<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Progressive KYC uses partial_identity; compat layer also references not_submitted.
     * Original enum omitted these values, which breaks MySQL updates after identity upload.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN kyc_status ENUM(
            'not_started',
            'pending',
            'in_review',
            'approved',
            'rejected',
            'expired',
            'partial_identity',
            'not_submitted'
        ) NOT NULL DEFAULT 'not_started'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN kyc_status ENUM(
            'not_started',
            'pending',
            'in_review',
            'approved',
            'rejected',
            'expired'
        ) NOT NULL DEFAULT 'not_started'");
    }
};
