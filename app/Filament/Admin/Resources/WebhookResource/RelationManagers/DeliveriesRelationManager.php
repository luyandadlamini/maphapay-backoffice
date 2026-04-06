<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WebhookResource\RelationManagers;

use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $recordTitleAttribute = 'uuid';

    protected static ?string $title = 'Delivery History';

    public function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('uuid')
                        ->label('Delivery ID')
                        ->limit(20)
                        ->tooltip(fn ($record) => $record->uuid)
                        ->copyable(),
                    Tables\Columns\TextColumn::make('event_type')
                        ->label('Event')
                        ->searchable(),
                    Tables\Columns\TextColumn::make('status')
                        ->colors(
                            [
                                'success' => 'delivered',
                                'warning' => 'pending',
                                'danger'  => 'failed',
                            ]
                        ),
                    Tables\Columns\TextColumn::make('attempt_number')
                        ->label('Attempt')
                        ->numeric(),
                    Tables\Columns\TextColumn::make('response_status')
                        ->label('HTTP Status')
                        ->placeholder('—'),
                    Tables\Columns\TextColumn::make('duration_ms')
                        ->label('Duration')
                        ->numeric()
                        ->suffix('ms')
                        ->placeholder('—'),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Created')
                        ->dateTime('M j, Y g:i:s A')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('delivered_at')
                        ->label('Delivered')
                        ->dateTime('M j, Y g:i:s A')
                        ->placeholder('—')
                        ->toggleable(isToggledHiddenByDefault: true),
                ]
            )
            ->defaultSort('created_at', 'desc')
            ->filters(
                [
                    Tables\Filters\SelectFilter::make('status')
                        ->options(
                            [
                                'pending'   => 'Pending',
                                'delivered' => 'Delivered',
                                'failed'    => 'Failed',
                            ]
                        ),
                    Tables\Filters\SelectFilter::make('event_type')
                        ->options(
                            fn () => $this->getOwnerRecord()->deliveries()
                                ->distinct('event_type')
                                ->pluck('event_type', 'event_type')
                                ->toArray()
                        ),
                ]
            )
            ->headerActions([])
            ->actions(
                [
                    Tables\Actions\ViewAction::make()
                        ->modalHeading('Delivery Details')
                        ->modalContent(fn ($record) => view('filament.admin.resources.webhook-resource.delivery-details', ['delivery' => $record])),
                    Tables\Actions\Action::make('retry')
                        ->label('Retry')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn ($record) => $record->status === 'failed')
                        ->requiresConfirmation()
                        ->action(
                            function ($record) {
                                $record->createRetry();

                                Notification::make()
                                    ->title('Webhook retry created')
                                    ->body('A new delivery attempt has been queued.')
                                    ->success()
                                    ->send();
                            }
                        ),
                ]
            )
            ->bulkActions([]);
    }
}
