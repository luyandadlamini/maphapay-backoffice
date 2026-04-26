<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\MtnMomoTransactionResource;
use App\Models\MtnMomoTransaction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class FailedMomoTransactionsWidget extends BaseWidget
{
    protected static ?string $heading = 'Failed MTN MoMo Transactions';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->check();
    }

    protected function getTableQuery(): Builder
    {
        return MtnMomoTransaction::query()
            ->where('status', MtnMomoTransaction::STATUS_FAILED)
            ->latest();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('mtn_reference_id')
                ->label('MoMo Reference')
                ->copyable()
                ->placeholder('—'),
            Tables\Columns\TextColumn::make('type')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'collection'   => 'info',
                    'disbursement' => 'warning',
                    default        => 'secondary',
                }),
            Tables\Columns\TextColumn::make('amount')
                ->money('SZL'),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->color('danger'),
            Tables\Columns\TextColumn::make('user.name')
                ->label('Customer')
                ->placeholder('—'),
            Tables\Columns\TextColumn::make('created_at')
                ->dateTime()
                ->sortable(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns($this->getTableColumns())
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(
                        fn (MtnMomoTransaction $record): string => MtnMomoTransactionResource::getUrl('edit', ['record' => $record])
                    ),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No failed MoMo transactions')
            ->emptyStateDescription('All MoMo transactions are processing normally.');
    }
}
