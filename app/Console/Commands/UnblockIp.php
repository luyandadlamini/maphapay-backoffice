<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IpBlockingService;
use Illuminate\Console\Command;

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

        if (! $this->ipBlockingService->isBlocked($ip)) {
            $this->info("IP {$ip} is not blocked.");
            return self::SUCCESS;
        }

        $this->ipBlockingService->unblockIp($ip);
        $this->info("IP {$ip} has been unblocked.");

        return self::SUCCESS;
    }
}
