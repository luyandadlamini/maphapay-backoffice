<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\AccountMembershipService;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Tenancy;

/**
 * One-shot repair: ensure a user has an active owner-level personal-wallet
 * AccountMembership so AccountPolicy::createMinor() / createMerchant /
 * createCompany return true.
 *
 * Idempotent. Reports state before and after.
 *
 *   php artisan accounts:repair-owner-membership user@example.com
 *   php artisan accounts:repair-owner-membership user@example.com --dry-run
 */
class RepairOwnerMembership extends Command
{
    protected $signature = 'accounts:repair-owner-membership
                            {email : The user email to inspect/repair}
                            {--dry-run : Report only; make no changes}';

    protected $description = 'Ensure a user has an active owner-level personal-wallet AccountMembership.';

    public function handle(
        AccountMembershipService $membershipService,
        Tenancy $tenancy,
    ): int {
        $email = (string) $this->argument('email');
        $dryRun = (bool) $this->option('dry-run');

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $this->line("User: {$user->email} (uuid={$user->uuid}, id={$user->id})");

        $memberships = AccountMembership::query()
            ->forUser($user->uuid)
            ->get();

        $this->line('Current memberships:');
        if ($memberships->isEmpty()) {
            $this->line('  (none)');
        }
        foreach ($memberships as $m) {
            $this->line(sprintf(
                '  - account_uuid=%s account_type=%s role=%s status=%s tenant_id=%s',
                $m->account_uuid,
                $m->account_type,
                $m->role,
                $m->status,
                $m->tenant_id,
            ));
        }

        $ownerPersonal = $memberships
            ->where('status', 'active')
            ->whereIn('account_type', AccountMembership::PERSONAL_ACCOUNT_TYPES)
            ->where('role', 'owner')
            ->first();

        if ($ownerPersonal !== null) {
            $this->info('OK: an active owner-level personal-wallet membership already exists. Nothing to repair.');

            return self::SUCCESS;
        }

        $this->warn('No active owner-level personal-wallet membership found. Repair needed.');

        if ($dryRun) {
            $this->line('--dry-run: skipping changes.');

            return self::SUCCESS;
        }

        $team = $user->ownedTeams()->where('personal_team', true)->first();
        if ($team === null) {
            $this->warn('User has no personal team. Creating one now…');
            $team = Team::forceCreate([
                'user_id'       => $user->id,
                'name'          => explode(' ', $user->name, 2)[0] . "'s Team",
                'personal_team' => true,
            ]);
            $user->ownedTeams()->save($team);
            $this->line("  Created team: id={$team->id} name={$team->name}");
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['team_id' => $team->id],
            ['name' => $team->name, 'plan' => 'default'],
        );

        $previousTenancyInitialized = $tenancy->initialized;
        $tenancy->initialize($tenant);

        // If this is a freshly created tenant its schema will be empty — run migrations.
        try {
            $tableCount = \Illuminate\Support\Facades\DB::connection('tenant')
                ->select("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE()");
            if (($tableCount[0]->cnt ?? 0) < 5) {
                $this->line('Fresh tenant schema detected — running tenant migrations…');
                Artisan::call('migrate', [
                    '--path'  => 'database/migrations/tenant',
                    '--force' => true,
                ]);
                $this->line(Artisan::output());
            }
        } catch (\Throwable) {
            // If schema check fails, attempt migration anyway.
            $this->line('Could not check schema — running tenant migrations defensively…');
            Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--force' => true]);
        }

        try {
            $personalAccount = Account::query()
                ->where('user_uuid', $user->uuid)
                ->whereIn('type', ['personal', 'standard'])
                ->orderBy('created_at')
                ->first();

            if ($personalAccount === null) {
                $this->line('No personal/standard account exists. Creating one…');
                $personalAccount = Account::create([
                    'uuid'      => (string) \Illuminate\Support\Str::uuid(),
                    'user_uuid' => $user->uuid,
                    'name'      => 'Maphapay Wallet',
                    'type'      => 'personal',
                    'status'    => 'active',
                ]);
            }

            if ($personalAccount === null) {
                $this->error('Failed to resolve or create a personal account.');

                return self::FAILURE;
            }

            $this->line("Personal account: uuid={$personalAccount->uuid} type={$personalAccount->type}");

            $membership = $membershipService->createOwnerMembership(
                $user,
                (string) $tenant->id,
                $personalAccount,
            );

            $this->info(sprintf(
                'Created/updated owner membership: account_uuid=%s account_type=%s role=%s status=%s',
                $membership->account_uuid,
                $membership->account_type,
                $membership->role,
                $membership->status,
            ));

            return self::SUCCESS;
        } finally {
            if (! $previousTenancyInitialized && $tenancy->initialized) {
                $tenancy->end();
            }
        }
    }
}
