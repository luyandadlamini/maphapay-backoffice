<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\FundManagement;

use App\Domain\Asset\Models\Asset;
use App\Domain\Treasury\Models\TreasurySnapshot;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class TreasuryPoolPage extends Page implements HasTable, HasForms
{
    use InteractsWithTable;
    use InteractsWithForms;
    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationLabel = 'Treasury Pool';

    protected static ?string $title = 'Treasury Pool';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.admin.pages.fund-management.treasury-pool';

    public array $treasuryBalances = [];

    public function mount(): void
    {
        $this->loadTreasuryBalances();
    }

    protected function loadTreasuryBalances(): void
    {
        $assets = Asset::active()->get();

        foreach ($assets as $asset) {
            $this->treasuryBalances[$asset->code] = [
                'code'      => $asset->code,
                'name'      => $asset->name,
                'type'      => $asset->type,
                'balance'   => $this->getTreasuryBalance($asset->code),
                'formatted' => $asset->formatAmount($this->getTreasuryBalance($asset->code)),
            ];
        }
    }

    protected function getTreasuryBalance(string $assetCode): int
    {
        $snapshot = TreasurySnapshot::where('asset_code', $assetCode)
            ->latest()
            ->first();

        return $snapshot?->balance ?? 0;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Asset::query()->active()
            )
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Currency')
                    ->badge()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name'),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge(),
                Tables\Columns\TextColumn::make('treasury_balance')
                    ->label('Treasury Balance')
                    ->getStateUsing(fn (Asset $record): string => $this->treasuryBalances[$record->code]['formatted'] ?? '0.00'),
                Tables\Columns\TextColumn::make('precision')
                    ->label('Precision'),
            ]);
    }
}
