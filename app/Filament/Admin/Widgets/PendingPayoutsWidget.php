<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\MtnMomoTransaction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingPayoutsWidget extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                MtnMomoTransaction::query()
                    ->where('status', 'pending')
                    ->where('type', 'disbursement')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('SZL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'    => 'warning',
                        'successful' => 'success',
                        'failed'     => 'danger',
                        default      => 'gray',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')
                            ->required()
                            ->minLength(5),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('approve-adjustments') ?? false)
                    ->action(function (MtnMomoTransaction $record, array $data): void {
                        $record->update([
                            'status' => 'successful',
                            'note'   => $data['reason'] . ' (Approved by: ' . auth()->id() . ')',
                        ]);
                        Notification::make()->title('Payout Approved')->success()->send();
                    }),
                Tables\Actions\Action::make('hold')
                    ->label('Hold')
                    ->color('warning')
                    ->icon('heroicon-o-pause')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')->required(),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('approve-adjustments') ?? false)
                    ->action(function (MtnMomoTransaction $record, array $data): void {
                        $record->update([
                            'note' => 'HOLD: ' . $data['reason'],
                        ]);
                        Notification::make()->title('Payout securely placed on hold')->warning()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')->required(),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('approve-adjustments') ?? false)
                    ->action(function (MtnMomoTransaction $record, array $data): void {
                        $record->update([
                            'status' => 'failed',
                            'note'   => 'REJECTED: ' . $data['reason'] . ' (By: ' . auth()->id() . ')',
                        ]);
                        Notification::make()->title('Payout Rejected')->danger()->send();
                    }),
            ])
            ->emptyStateHeading('No pending payouts')
            ->emptyStateDescription('All disbursements have been processed.');
    }
}
