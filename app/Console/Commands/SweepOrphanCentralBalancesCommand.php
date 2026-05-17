<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Shared\Concerns\WithTenantContext;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SweepOrphanCentralBalancesCommand extends Command
{
    use WithTenantContext;

    protected $signature = 'maphapay:sweep-orphan-central-balances
                            {--apply : Execute the migration; default is dry-run}
                            {--user= : Scope to a single user email address}';

    protected $description = 'Detect and migrate orphaned central-DB account balances to the correct tenant DB. Safe dry-run by default; pass --apply to write.';

    public function handle(): int
    {
        $centralAccounts = DB::connection('mysql')
            ->table('accounts as a')
            ->join('account_balances as ab', 'a.uuid', '=', 'ab.account_uuid')
            ->where('ab.balance', '>', 0)
            ->when($this->option('user'), function ($q): void {
                $q->join('users as u', 'a.user_uuid', '=', 'u.uuid')
                    ->where('u.email', $this->option('user'));
            })
            ->select('a.uuid as central_account_uuid', 'a.user_uuid', 'ab.asset_code', 'ab.balance')
            ->get();

        /** @var array<int, array{user_uuid: string, central_account_uuid: string, tenant_account_uuid: string, tenant_id: string, asset_code: string, balance: int}> $plan */
        $plan = [];

        foreach ($centralAccounts as $row) {
            $membership = AccountMembership::query()
                ->where('user_uuid', $row->user_uuid)
                ->where('status', 'active')
                ->first();

            if ($membership === null) {
                $this->warn("No active membership for user {$row->user_uuid} — skipping");

                continue;
            }

            // Skip rows where the central account uuid already matches the tenant uuid
            // (these are not orphans — they are already canonical).
            if ($row->central_account_uuid === $membership->account_uuid) {
                $this->line("User {$row->user_uuid}: central_uuid matches tenant_uuid — already canonical, skipping");

                continue;
            }

            $plan[] = [
                'user_uuid'            => $row->user_uuid,
                'central_account_uuid' => $row->central_account_uuid,
                'tenant_account_uuid'  => $membership->account_uuid,
                'tenant_id'            => $membership->tenant_id,
                'asset_code'           => $row->asset_code,
                'balance'              => (int) $row->balance,
            ];
        }

        if ($plan === []) {
            $this->info('No orphan balances found.');

            return self::SUCCESS;
        }

        $this->table(
            ['user_uuid', 'central_uuid', 'tenant_uuid', 'tenant_id', 'asset_code', 'balance'],
            array_map(fn (array $p): array => array_values($p), $plan),
        );

        if (! $this->option('apply')) {
            $this->info(sprintf('Dry run — %d row(s) would be migrated. Pass --apply to execute.', count($plan)));

            return self::SUCCESS;
        }

        $migrated = 0;
        $failed = 0;

        foreach ($plan as $item) {
            $tenant = Tenant::find($item['tenant_id']);

            if ($tenant === null) {
                $this->error("Tenant {$item['tenant_id']} not found — skipping user {$item['user_uuid']}");
                $failed++;

                continue;
            }

            try {
                $this->withAccountTenancy($item['tenant_account_uuid'], function () use ($item): void {
                    DB::transaction(function () use ($item): void {
                        // Ensure the account row exists in the tenant DB.
                        Account::updateOrCreate(
                            ['uuid' => $item['tenant_account_uuid']],
                            [
                                'user_uuid' => $item['user_uuid'],
                                'name'      => 'Migrated Wallet',
                                'type'      => 'personal',
                                'frozen'    => false,
                                'status'    => 'active',
                            ],
                        );

                        // Upsert the balance in the tenant DB (idempotent).
                        AccountBalance::updateOrCreate(
                            [
                                'account_uuid' => $item['tenant_account_uuid'],
                                'asset_code'   => $item['asset_code'],
                            ],
                            ['balance' => $item['balance']],
                        );
                    });
                });

                // Zero the central balance row (does NOT delete the account row —
                // that is left for a future clean-up pass once data is verified).
                DB::connection('mysql')
                    ->table('account_balances')
                    ->where('account_uuid', $item['central_account_uuid'])
                    ->where('asset_code', $item['asset_code'])
                    ->update(['balance' => 0]);

                $this->info(
                    "Migrated {$item['asset_code']} balance ({$item['balance']}) " .
                    "for user {$item['user_uuid']} → tenant {$item['tenant_id']}",
                );

                $migrated++;
            } catch (Throwable $e) {
                $this->error(
                    "FAILED for user {$item['user_uuid']} ({$item['asset_code']}): {$e->getMessage()}",
                );
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Summary: migrated={$migrated} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
