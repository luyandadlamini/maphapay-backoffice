<?php

namespace App\Console\Commands;

use App\Domain\Account\Services\AccountService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CreateUserAccountsCommand extends Command
{
    protected $signature = 'users:create-accounts 
                            {--user= : Specific user UUID to create account for}
                            {--all : Create accounts for all users without accounts}
                            {--name= : Account name suffix (default: "Main Account")}';

    protected $description = 'Create accounts for users who do not have one';

    public function handle(): int
    {
        $userUuid = $this->option('user');
        $createAll = $this->option('all');
        $nameSuffix = $this->option('name') ?? 'Main Account';

        if (! $userUuid && ! $createAll) {
            $this->error('Please specify either --user=<uuid> or --all');
            $this->info('Usage: php artisan users:create-accounts --all');
            $this->info('       php artisan users:create-accounts --user=<uuid>');

            return 1;
        }

        $accountService = app(AccountService::class);

        if ($userUuid) {
            return $this->createAccountForUser($accountService, $userUuid, $nameSuffix);
        }

        return $this->createAccountsForAllUsers($accountService, $nameSuffix);
    }

    private function createAccountForUser(AccountService $accountService, string $userUuid, string $nameSuffix): int
    {
        $user = User::where('uuid', $userUuid)->first();

        if (! $user) {
            $this->error("User with UUID {$userUuid} not found.");

            return 1;
        }

        if ($user->accounts()->exists()) {
            $this->warn("User {$user->name} ({$user->email}) already has {$user->accounts()->count()} account(s).");

            $user->accounts->each(function ($account) {
                $this->line("  - {$account->name} (UUID: {$account->uuid})");
            });

            return 0;
        }

        try {
            $accountName = $user->name . "'s " . $nameSuffix;
            $accountUuid = $accountService->createForUser($user->uuid, $accountName);

            $this->info("✅ Created account for {$user->name} ({$user->email})");
            $this->line("   Account Name: {$accountName}");
            $this->line("   Account UUID: {$accountUuid}");

            Log::info('Account created via CLI command', [
                'user_uuid'    => $user->uuid,
                'user_email'   => $user->email,
                'account_uuid' => $accountUuid,
                'account_name' => $accountName,
            ]);

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed to create account: {$e->getMessage()}");
            Log::error('Failed to create account via CLI', [
                'user_uuid' => $user->uuid,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }

    private function createAccountsForAllUsers(AccountService $accountService, string $nameSuffix): int
    {
        $usersWithoutAccounts = User::whereDoesntHave('accounts')->get();

        if ($usersWithoutAccounts->isEmpty()) {
            $this->info('All users already have accounts.');

            return 0;
        }

        $this->info("Found {$usersWithoutAccounts->count()} user(s) without accounts.");

        $success = 0;
        $failed = 0;

        $this->newLine();
        $this->table(
            ['Email', 'Name', 'Status', 'Account UUID'],
            []
        );

        foreach ($usersWithoutAccounts as $user) {
            $this->line("Processing: {$user->email}...");

            try {
                $accountName = $user->name . "'s " . $nameSuffix;
                $accountUuid = $accountService->createForUser($user->uuid, $accountName);

                $this->info("  ✅ Created: {$accountUuid}");

                Log::info('Account created via CLI (batch)', [
                    'user_uuid'    => $user->uuid,
                    'user_email'   => $user->email,
                    'account_uuid' => $accountUuid,
                ]);

                $success++;
            } catch (\Throwable $e) {
                $this->error("  ❌ Failed: {$e->getMessage()}");

                Log::error('Failed to create account via CLI (batch)', [
                    'user_uuid' => $user->uuid,
                    'user_email' => $user->email,
                    'error'     => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        $this->newLine();
        $this->info("Summary: {$success} created, {$failed} failed");

        return $failed > 0 ? 1 : 0;
    }
}
