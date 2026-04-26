<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\RevenueTarget;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * REQ-ALR-001: read-only revenue target anomaly detection.
 *
 * Revenue targets live on the tenant connection when tenancy is initialized.
 * Finance notifications must target {@see User} records on the central (landlord) database.
 */
final class RevenueAnomalyScanner
{
    /**
     * @param  array<string, mixed>  $logContext
     */
    public function run(bool $notifyFromCliOption, bool $notifyFromConfig, array $logContext = []): int
    {
        Log::info('revenue_anomaly_scan.start', array_merge(['at' => now()->toIso8601String()], $logContext));

        $anomalyFound = false;

        try {
            if (Schema::hasTable('revenue_targets')) {
                $anomalyFound = RevenueTarget::query()->where('amount', '<=', 0)->exists();
            }
        } catch (Throwable $e) {
            Log::error(
                'revenue_anomaly_scan.query_failed',
                array_merge(['error' => $e->getMessage()], $logContext)
            );

            return 1;
        }

        if ($anomalyFound) {
            Log::warning(
                'revenue_anomaly_scan.zero_or_negative_target_detected',
                $logContext
            );

            $sendNotify = $notifyFromCliOption || $notifyFromConfig;

            if ($sendNotify) {
                $this->notifyFinanceUsersOnCentralDatabase();
            }
        }

        Log::info(
            'revenue_anomaly_scan.complete',
            array_merge(['anomaly_found' => $anomalyFound], $logContext)
        );

        return 0;
    }

    private function notifyFinanceUsersOnCentralDatabase(): void
    {
        $users = $this->financeUsersForNotification()->cursor();

        foreach ($users as $user) {
            Notification::make()
                ->title(__('Revenue anomaly scan'))
                ->body(__('At least one revenue target has amount ≤ 0. Review Targets & forecasts in the admin panel.'))
                ->warning()
                ->sendToDatabase($user);
        }
    }

    /**
     * @return Builder<User>
     */
    private function financeUsersForNotification(): Builder
    {
        $centralConnection = (string) config('tenancy.database.central_connection', 'central');

        $query = User::query();

        try {
            if (function_exists('tenancy') && tenancy()->initialized) {
                $query = User::on($centralConnection);
            }
        } catch (Throwable) {
            // Landlord context or tenancy unavailable: default connection users.
        }

        return $query
            ->whereHas(
                'roles',
                static fn ($roleQuery) => $roleQuery->whereIn('name', ['finance-lead', 'super-admin'])
            );
    }
}
