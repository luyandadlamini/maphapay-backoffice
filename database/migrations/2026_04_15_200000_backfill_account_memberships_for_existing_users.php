<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class () extends Migration {
    private const BACKFILL_MARKER = '2026_04_15_200000_backfill_account_memberships_for_existing_users';

    public function up(): void
    {
        $marker = json_encode([
            'backfill_migration' => self::BACKFILL_MARKER,
        ], JSON_THROW_ON_ERROR);

        Tenant::query()
            ->cursor()
            ->each(function (Tenant $tenant) use ($marker): void {
                try {
                    $tenant->run(function () use ($tenant, $marker): void {
                        if (! Schema::connection('tenant')->hasTable('accounts')) {
                            return;
                        }

                        DB::connection('tenant')
                            ->table('accounts')
                            ->whereNull('deleted_at')
                            ->whereNotNull('user_uuid')
                            ->orderBy('id')
                            ->cursor()
                            ->each(function (object $account) use ($tenant, $marker): void {
                                $exists = DB::connection('central')
                                    ->table('account_memberships')
                                    ->where('account_uuid', $account->uuid)
                                    ->where('user_uuid', $account->user_uuid)
                                    ->exists();

                                if ($exists) {
                                    return;
                                }

                                DB::connection('central')
                                    ->table('account_memberships')
                                    ->insert([
                                        'id' => (string) Str::uuid(),
                                        'user_uuid' => $account->user_uuid,
                                        'tenant_id' => $tenant->id,
                                        'account_uuid' => $account->uuid,
                                        'account_type' => $account->type === 'standard' ? 'personal' : $account->type,
                                        'role' => 'owner',
                                        'status' => 'active',
                                        'joined_at' => $account->created_at,
                                        'permissions_override' => $marker,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                            });
                    });
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Backfill failed for tenant', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // Backfilled memberships become live access records once deployed, so removing them during rollback
        // would orphan existing accounts and revoke production access.
    }
};
