<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets;

use App\Domain\Wallet\Models\WalletLinking;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Filament\Admin\Pages\Wallets\Concerns\HasMockWalletActions;
use App\Models\MtnMomoTransaction;
use Filament\Pages\Page;

abstract class AbstractOverviewPage extends Page
{
    use HasMockWalletActions;

    protected static ?string $navigationGroup = 'E-Wallets';

    protected static string $view = 'filament.admin.pages.wallets.overview';

    public static string $providerKey = '';

    public static string $providerLabel = '';

    public static string $mockEndpointPath = '';

    public function getTitle(): string
    {
        return static::$providerLabel . ' — Overview';
    }

    public function getViewData(): array
    {
        return [
            'providerLabel'     => static::$providerLabel,
            'linkedActiveCount' => WalletLinking::query()
                ->where('provider', static::$providerKey)
                ->where('link_status', WalletLinking::STATUS_ACTIVE)
                ->count(),
            'linkedPendingCount' => WalletLinking::query()
                ->where('provider', static::$providerKey)
                ->where('link_status', WalletLinking::STATUS_PENDING)
                ->count(),
            'transactionsToday' => $this->countTodayTransactions(),
            'successRate7d'     => $this->successRate7d(),
            'lastActivity'      => $this->lastActivity(),
        ];
    }

    private function countTodayTransactions(): int
    {
        if (static::$providerKey === WalletLinking::PROVIDER_MTN_MOMO) {
            return MtnMomoTransaction::query()->whereDate('created_at', today())->count();
        }

        return WalletProviderTransaction::query()
            ->where('provider_id', static::$providerKey)
            ->whereDate('created_at', today())
            ->count();
    }

    private function successRate7d(): ?float
    {
        if (static::$providerKey === WalletLinking::PROVIDER_MTN_MOMO) {
            $q = MtnMomoTransaction::query()->where('created_at', '>=', now()->subDays(7));
        } else {
            $q = WalletProviderTransaction::query()
                ->where('provider_id', static::$providerKey)
                ->where('created_at', '>=', now()->subDays(7));
        }

        $total = (clone $q)->count();
        if ($total === 0) {
            return null;
        }

        $success = (clone $q)->where('status', WalletProviderTransaction::STATUS_SUCCESSFUL)->count();

        return round(($success / $total) * 100, 1);
    }

    private function lastActivity(): ?string
    {
        $row = static::$providerKey === WalletLinking::PROVIDER_MTN_MOMO
            ? MtnMomoTransaction::query()->latest('created_at')->first()
            : WalletProviderTransaction::query()
                ->where('provider_id', static::$providerKey)
                ->latest('created_at')
                ->first();

        return $row?->created_at?->diffForHumans();
    }
}
