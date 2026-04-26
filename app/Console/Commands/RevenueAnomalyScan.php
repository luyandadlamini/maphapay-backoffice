<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Services\RevenueAnomalyScanner;
use Illuminate\Console\Command;

/**
 * REQ-ALR-001 v1: read-only revenue anomaly checks (log + optional in-app notifications).
 * Extend detection rules when finance defines signals; no writes to ledger here.
 *
 * For production cron, prefer {@see RevenueAnomalyScanForTenants} so each tenant database is scanned.
 */
class RevenueAnomalyScan extends Command
{
    protected $signature = 'revenue:scan-anomalies {--notify : Send Filament database notifications when anomalies are detected}';

    protected $description = 'Scan for basic revenue-target anomalies (read-only); logs always, optional DB notifications';

    public function handle(RevenueAnomalyScanner $scanner): int
    {
        $notifyFromConfig = (bool) config('maphapay.revenue_anomaly_scan_send_database_notifications', false);
        $notifyFromCli = (bool) $this->option('notify');

        $exit = $scanner->run($notifyFromCli, $notifyFromConfig);

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }
}
