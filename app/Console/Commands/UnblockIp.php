<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IpBlockingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class UnblockIp extends Command
{
    protected $signature = 'ip:unblock {ip : The IP address to unblock}';

    protected $description = 'Unblock a previously blocked IP address';

    public function __construct(
        private readonly IpBlockingService $ipBlockingService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ip = $this->argument('ip');

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error("Invalid IP address: {$ip}");
            return self::FAILURE;
        }

        // Clear IpBlockingService blocks (database + cache)
        $this->ipBlockingService->unblockIp($ip);

        // Clear IpBlocking middleware blocks (different cache keys)
        Cache::forget("ip_blocked:{$ip}");
        Cache::forget("ip_failed_attempts:{$ip}");

        // Remove from permanent blacklist if present
        $blacklistKey = 'ip_blacklist';
        $blacklist = Cache::get($blacklistKey, []);
        if (in_array($ip, $blacklist)) {
            $blacklist = array_values(array_filter($blacklist, fn ($i) => $i !== $ip));
            Cache::forever($blacklistKey, $blacklist);
        }

        $this->info("IP {$ip} has been unblocked (all systems).");

        return self::SUCCESS;
    }
}
