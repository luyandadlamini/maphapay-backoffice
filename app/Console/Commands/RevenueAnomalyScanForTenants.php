<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Services\RevenueAnomalyScanner;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs {@see RevenueAnomalyScanner} once per landlord tenant (REQ-ALR-001).
 *
 * Targets live on tenant databases; finance notifications use the central connection.
 */
class RevenueAnomalyScanForTenants extends Command
{
    protected $signature = 'revenue:scan-anomalies:for-tenants
                            {--notify : Send Filament database notifications when anomalies are detected}
                            {--tenant= : Only scan this tenant ID (UUID)}';

    protected $description = 'Scan all tenant databases for revenue-target anomalies (read-only)';

    public function handle(RevenueAnomalyScanner $scanner): int
    {
        $tenantId = $this->option('tenant');

        $notifyFromConfig = (bool) config('maphapay.revenue_anomaly_scan_send_database_notifications', false);
        $notifyFromCli = (bool) $this->option('notify');

        $anyFailure = false;

        if ($tenantId !== null && $tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                $this->error("Tenant not found: {$tenantId}");

                return self::FAILURE;
            }

            $this->scanOneTenant($scanner, $tenant, $notifyFromCli, $notifyFromConfig, $anyFailure);

            return $anyFailure ? self::FAILURE : self::SUCCESS;
        }

        $tenantCount = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $tenantCount++;
            $this->scanOneTenant($scanner, $tenant, $notifyFromCli, $notifyFromConfig, $anyFailure);
        }

        if ($tenantCount === 0) {
            $this->warn('No tenants to scan.');

            return self::SUCCESS;
        }

        return $anyFailure ? self::FAILURE : self::SUCCESS;
    }

    private function scanOneTenant(
        RevenueAnomalyScanner $scanner,
        Tenant $tenant,
        bool $notifyFromCli,
        bool $notifyFromConfig,
        bool &$anyFailure
    ): void {
        try {
            tenancy()->initialize($tenant);

            $exit = $scanner->run(
                $notifyFromCli,
                $notifyFromConfig,
                ['tenant_id' => $tenant->id]
            );

            if ($exit !== 0) {
                $anyFailure = true;
            }
        } catch (Throwable $e) {
            $anyFailure = true;
            Log::error('revenue_anomaly_scan.tenant_iteration_failed', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }
}
