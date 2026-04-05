<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Onboarding\Services\DefaultUserResourceProvisioningService;
use App\Domain\Rewards\Services\RewardsService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

class ProvisionUserDefaultsCommand extends Command
{
    protected $signature = 'users:provision-defaults
                            {--all-three=* : User UUID(s) to provision wallet + rewards + default MCard}
                            {--all-three-email=* : User email(s) to provision wallet + rewards + default MCard}
                            {--rewards-only=* : User UUID(s) to provision rewards profile only}
                            {--rewards-only-email=* : User email(s) to provision rewards profile only}';

    protected $description = 'Provision default user resources by UUID for operations/backoffice.';

    public function handle(
        DefaultUserResourceProvisioningService $provisioningService,
        RewardsService $rewardsService,
    ): int {
        $allThreeUuids = $this->normalizeUuidOption($this->option('all-three'));
        $allThreeEmails = $this->normalizeEmailOption($this->option('all-three-email'));
        $rewardsOnlyUuids = $this->normalizeUuidOption($this->option('rewards-only'));
        $rewardsOnlyEmails = $this->normalizeEmailOption($this->option('rewards-only-email'));

        if ($allThreeUuids === [] && $allThreeEmails === [] && $rewardsOnlyUuids === [] && $rewardsOnlyEmails === []) {
            $this->error('Provide at least one target via --all-three, --all-three-email, --rewards-only, or --rewards-only-email.');
            $this->line('Example: php artisan users:provision-defaults --all-three=<uuid> --rewards-only-email=<email>');

            return self::FAILURE;
        }

        $failed = 0;
        $succeeded = 0;

        if ($allThreeUuids !== [] || $allThreeEmails !== []) {
            $this->newLine();
            $this->info('Provisioning wallet + rewards + default MCard...');

            foreach ($this->resolveUsers($allThreeUuids, $allThreeEmails) as $target => $user) {
                if (! $user) {
                    $failed++;
                    $this->error("✗ {$target} not found");
                    continue;
                }

                try {
                    $provisioningService->ensureForUser($user);
                    $succeeded++;
                    $this->info("✓ {$user->uuid} ({$user->email}) provisioned for all three resources");
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("✗ {$user->uuid} ({$user->email}) failed: {$exception->getMessage()}");
                }
            }
        }

        if ($rewardsOnlyUuids !== [] || $rewardsOnlyEmails !== []) {
            $this->newLine();
            $this->info('Provisioning rewards profile only...');

            foreach ($this->resolveUsers($rewardsOnlyUuids, $rewardsOnlyEmails) as $target => $user) {
                if (! $user) {
                    $failed++;
                    $this->error("✗ {$target} not found");
                    continue;
                }

                try {
                    $rewardsService->getProfile($user);
                    $succeeded++;
                    $this->info("✓ {$user->uuid} ({$user->email}) rewards profile ensured");
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("✗ {$user->uuid} ({$user->email}) failed: {$exception->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->line("Completed. Success: {$succeeded}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeUuidOption(mixed $value): array
    {
        $rawValues = Arr::wrap($value);
        $tokens = [];

        foreach ($rawValues as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            foreach (explode(',', $raw) as $piece) {
                $uuid = trim($piece);
                if ($uuid !== '') {
                    $tokens[] = $uuid;
                }
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($tokens));

        return $unique;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeEmailOption(mixed $value): array
    {
        $rawValues = Arr::wrap($value);
        $tokens = [];

        foreach ($rawValues as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            foreach (explode(',', $raw) as $piece) {
                $email = strtolower(trim($piece));
                if ($email !== '') {
                    $tokens[] = $email;
                }
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($tokens));

        return $unique;
    }

    /**
     * @param list<string> $uuids
     * @param list<string> $emails
     * @return array<string, User|null>
     */
    private function resolveUsers(array $uuids, array $emails): array
    {
        $resolved = [];

        foreach ($uuids as $uuid) {
            $resolved[$uuid] = User::query()->where('uuid', $uuid)->first();
        }

        foreach ($emails as $email) {
            $resolved[$email] = User::query()->where('email', $email)->first();
        }

        return $resolved;
    }
}
