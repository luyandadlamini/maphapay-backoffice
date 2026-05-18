<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets;

use App\Domain\Wallet\Models\WalletLinking;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Filament\Admin\Pages\Wallets\Concerns\HasMockWalletActions;
use App\Models\MtnMomoTransaction;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

abstract class AbstractTransactionsPage extends Page implements HasTable
{
    use HasMockWalletActions;
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'E-Wallets';

    protected static string $view = 'filament.admin.pages.wallets.table-page';

    public static string $providerKey = '';

    public static string $providerLabel = '';

    public static string $mockEndpointPath = '';

    public function getTitle(): string
    {
        return static::$providerLabel . ' — Transactions';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->transactionsQuery())
            ->columns($this->transactionColumns())
            ->defaultSort('created_at', 'desc');
    }

    protected function transactionsQuery(): Builder
    {
        if (static::$providerKey === WalletLinking::PROVIDER_MTN_MOMO) {
            return MtnMomoTransaction::query();
        }

        return WalletProviderTransaction::query()->where('provider_id', static::$providerKey);
    }

    /** @return array<int, TextColumn> */
    protected function transactionColumns(): array
    {
        if (static::$providerKey === WalletLinking::PROVIDER_MTN_MOMO) {
            return [
                TextColumn::make('id'),
                TextColumn::make('type'),
                TextColumn::make('status')->badge(),
                TextColumn::make('amount')->money('SZL'),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i'),
            ];
        }

        return [
            TextColumn::make('id'),
            TextColumn::make('type'),
            TextColumn::make('status')->badge(),
            TextColumn::make('amount_minor')->formatStateUsing(fn (int $v): string => number_format($v / 100, 2)),
            TextColumn::make('currency'),
            TextColumn::make('created_at')->dateTime('Y-m-d H:i'),
        ];
    }
}
