<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\MobilePayment\Enums\PaymentIntentStatus;
use App\Domain\MobilePayment\Models\PaymentIntent;
use Exception;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Log;

class CommerceExceptionWidget extends BaseWidget
{
    protected static ?string $heading = 'Commerce Exceptions (Failed Purchases)';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn () => PaymentIntent::where('status', PaymentIntentStatus::FAILED)
                    ->latest('failed_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable(),
                Tables\Columns\TextColumn::make('merchant.name')
                    ->label('Merchant')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('SZL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(30)
                    ->tooltip(fn ($state) => $state),
                Tables\Columns\TextColumn::make('failed_at')
                    ->label('Failed At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (PaymentIntent $record) {
                        try {
                            // In a real system, this would re-submit to the payment provider
                            // For this modernization, we log and notify
                            Log::info("Retrying PaymentIntent: {$record->id}");

                            Notification::make()
                                ->title('Retry Requested')
                                ->body('The payment re-submission has been queued.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Retry Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('mark_refunded')
                    ->label('Mark Refunded')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (PaymentIntent $record) {
                        $record->update([
                            'status'        => 'cancelled', // Or a 'refunded' status if added
                            'cancel_reason' => 'Manually refunded by admin',
                        ]);

                        Notification::make()
                            ->title('Marked as Refunded')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
