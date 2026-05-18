<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\AccountProvisioningService;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Inspect or repair a user's central directory provisioning state.
 *
 * Delegates to {@see AccountProvisioningService} which is the canonical
 * orchestrator. Use this command for ad-hoc operations support and for
 * bulk backfill of legacy users that predate the central directory
 * (production diagnostic on 2026-05-18 identified Mickey, Khanya, and
 * Sandelwe in this state — see commit history for context).
 *
 *   php artisan accounts:repair-owner-membership user@example.com
 *   php artisan accounts:repair-owner-membership user@example.com --dry-run
 *   php artisan accounts:repair-owner-membership --all
 *   php artisan accounts:repair-owner-membership --all --dry-run
 *
 * The --all mode walks every User row that has no active AccountMembership
 * and is not a known service ledger sink (admin/system/suspense/treasury/pool),
 * then runs the same idempotent provisioning for each.
 */
class RepairOwnerMembership extends Command
{
    /**
     * Service ledger sinks intentionally excluded from --all backfill.
     * These accounts exist as bare User rows by design (see SystemUsersSeeder).
     */
    private const SERVICE_EMAIL_PREFIXES = [
        'admin@',
        'system@',
        'suspense@',
        'treasury@',
        'pool@',
    ];

    protected $signature = 'accounts:repair-owner-membership
                            {email? : The user email to inspect/repair (omit when using --all)}
                            {--all : Repair every user missing an active membership (excludes service accounts)}
                            {--dry-run : Report only; make no changes}';

    protected $description = 'Ensure a user has an active owner-level personal-wallet AccountMembership.';

    public function handle(AccountProvisioningService $provisioningService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ((bool) $this->option('all')) {
            return $this->handleBulkBackfill($provisioningService, $dryRun);
        }

        $email = (string) ($this->argument('email') ?? '');
        if ($email === '') {
            $this->error('Provide an email argument or use --all.');

            return self::FAILURE;
        }

        return $this->handleSingleUser($provisioningService, $email, $dryRun);
    }

    private function handleSingleUser(AccountProvisioningService $provisioningService, string $email, bool $dryRun): int
    {
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

        $alreadyOk = $memberships
            ->where('status', 'active')
            ->whereIn('account_type', AccountMembership::PERSONAL_ACCOUNT_TYPES)
            ->where('role', 'owner')
            ->isNotEmpty();

        if ($alreadyOk) {
            $this->info('OK: an active owner-level personal-wallet membership already exists. Nothing to repair.');

            return self::SUCCESS;
        }

        $this->warn('No active owner-level personal-wallet membership found. Repair needed.');

        if ($dryRun) {
            $this->line('--dry-run: skipping changes.');

            return self::SUCCESS;
        }

        try {
            $membership = $provisioningService->ensureProvisioned($user);
        } catch (Throwable $exception) {
            $this->error('Repair failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Provisioned: account_uuid=%s account_type=%s role=%s status=%s tenant_id=%s',
            $membership->account_uuid,
            $membership->account_type,
            $membership->role,
            $membership->status,
            $membership->tenant_id,
        ));

        return self::SUCCESS;
    }

    private function handleBulkBackfill(AccountProvisioningService $provisioningService, bool $dryRun): int
    {
        $activeMemberUuids = AccountMembership::query()
            ->where('status', 'active')
            ->pluck('user_uuid');

        $targetUsersQuery = User::query()
            ->whereNotIn('uuid', $activeMemberUuids)
            ->whereNotNull('email');

        foreach (self::SERVICE_EMAIL_PREFIXES as $prefix) {
            $targetUsersQuery->where('email', 'NOT LIKE', $prefix . '%');
        }

        $targets = $targetUsersQuery->get(['id', 'uuid', 'email', 'name']);

        $this->line(sprintf(
            '%s mode: %d user(s) without active membership (excluding service accounts).',
            $dryRun ? 'Dry-run' : 'Backfill',
            $targets->count(),
        ));

        if ($targets->isEmpty()) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $repaired = 0;
        $failed = 0;

        foreach ($targets as $user) {
            $this->line(sprintf('- %s (id=%d uuid=%s)', $user->email, $user->id, $user->uuid));

            if ($dryRun) {
                continue;
            }

            try {
                $provisioningService->ensureProvisioned($user);
                $repaired++;
                $this->line('    OK');
            } catch (Throwable $exception) {
                $failed++;
                $this->error('    FAILED: ' . $exception->getMessage());
            }
        }

        if ($dryRun) {
            $this->info('--dry-run complete. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Backfill complete: %d repaired, %d failed.', $repaired, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
