<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'account_number')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->string('account_number', 20)->nullable()->after('name');
            });
        }

        $prefix = (string) config('banking.account_prefix', '8');
        $bodyLength = max(1, 10 - strlen($prefix));

        DB::table('accounts')
            ->select(['id', 'account_number'])
            ->whereNull('account_number')
            ->orderBy('id')
            ->get()
            ->each(function (object $account) use ($prefix, $bodyLength): void {
                do {
                    $maxBody = (10 ** $bodyLength) - 1;
                    $candidate = $prefix . str_pad((string) random_int(0, $maxBody), $bodyLength, '0', STR_PAD_LEFT);
                } while (
                    DB::table('accounts')
                        ->where('account_number', $candidate)
                        ->exists()
                );

                DB::table('accounts')
                    ->where('id', $account->id)
                    ->update(['account_number' => $candidate]);
            });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->unique('account_number');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('accounts', 'account_number')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['account_number']);
            $table->dropColumn('account_number');
        });
    }
};
