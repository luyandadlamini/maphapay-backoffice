<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Account\Models\AdjustmentRequest;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingAdjustmentsWidget extends BaseWidget
{
    protected static ?string $heading = 'Pending Manual Adjustments';

    protected int|string|array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return AdjustmentRequest::query()
            ->where('status', 'pending')
            ->latest();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('account.user.name')
                ->label('Customer')
                ->placeholder('—'),
            Tables\Columns\TextColumn::make('amount')
                ->money('SZL')
                ->sortable(),
            Tables\Columns\TextColumn::make('type')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'credit' => 'success',
                    'debit'  => 'danger',
                    default  => 'secondary',
                }),
            Tables\Columns\TextColumn::make('reason')
                ->limit(40),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->color('warning'),
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
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->url(
                        fn (AdjustmentRequest $record): string => \App\Filament\Admin\Resources\AdjustmentRequestResource::getUrl('edit', ['record' => $record])
                    ),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No pending adjustments')
            ->emptyStateDescription('All adjustment requests have been processed.');
    }
}
