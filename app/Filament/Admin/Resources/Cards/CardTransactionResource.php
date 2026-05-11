<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardTransaction;
use App\Filament\Admin\Resources\Cards\CardTransactionResource\Pages;
use App\Support\Backoffice\AdminActionGovernance;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardTransactionResource extends Resource
{
    protected static ?string $model = CardTransaction::class;

    protected static ?string $navigationGroup = 'Cards';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?int $navigationSort = 12;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.full_name')
                    ->label('Cardholder')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('card.last4')
                    ->label('Card')
                    ->formatStateUsing(fn (?string $state): string => $state ? '**** ' . $state : '—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('merchant_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('merchant_category')
                    ->label('Category')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state, CardTransaction $record): string => number_format($state / 100, 2) . ' ' . $record->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_amount')
                    ->label('Billed Amount')
                    ->numeric(2)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'settled'    => 'success',
                        'authorised' => 'info',
                        'reversed'   => 'warning',
                        'refunded'   => 'gray',
                        'declined'   => 'danger',
                        default      => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Authorised At')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('settled_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'authorised' => 'Authorised',
                        'settled'    => 'Settled',
                        'reversed'   => 'Reversed',
                        'refunded'   => 'Refunded',
                        'declined'   => 'Declined',
                    ])
                    ->multiple(),
                Tables\Filters\SelectFilter::make('currency')
                    ->options(['SZL' => 'SZL', 'ZAR' => 'ZAR', 'USD' => 'USD']),
                Tables\Filters\Filter::make('card_id')
                    ->label('Card ID')
                    ->form([
                        Forms\Components\TextInput::make('card_id')->label('Card UUID'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['card_id'] ?? null),
                        fn (Builder $q): Builder => $q->whereRaw('card_id = ?', [$data['card_id']]),
                    )),
                Tables\Filters\Filter::make('user_id')
                    ->label('User ID')
                    ->form([
                        Forms\Components\TextInput::make('user_id')->label('User UUID'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['user_id'] ?? null),
                        fn (Builder $q): Builder => $q->whereRaw('user_id = ?', [$data['user_id']]),
                    )),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from'),
                        Forms\Components\DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('open_dispute')
                    ->label('Open Dispute')
                    ->icon('heroicon-o-exclamation-circle')
                    ->color('warning')
                    ->visible(fn (CardTransaction $record): bool => $record->status === 'settled'
                        && Gate::forUser(auth()->user())->allows('create', CardDispute::class))
                    ->url(fn (CardTransaction $record): string => CardDisputeResource::getUrl('create') . '?card_transaction_id=' . urlencode((string) $record->id)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('exportSelected')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('export', new CardTransaction()))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (Collection $records, array $data): StreamedResponse {
                        return static::exportTransactions($records, (string) $data['reason']);
                    }),
            ]);
    }

    /**
     * @param Collection<int, CardTransaction> $records
     */
    public static function exportTransactions(Collection $records, string $reason): StreamedResponse
    {
        $first = $records->first();
        Gate::authorize('export', $first instanceof CardTransaction ? $first : new CardTransaction());

        /** @var \App\Models\User|null $actor */
        $actor = auth()->user();

        $filename = 'card-transactions-' . now()->format('Y-m-d-His') . '.csv';

        app(AdminActionGovernance::class)->auditDirectAction(
            workspace: 'compliance',
            action: 'backoffice.card_transactions.exported',
            reason: $reason,
            metadata: [
                'export_scope' => 'selected',
                'record_count' => $records->count(),
                'filename'     => $filename,
                'actor_email'  => $actor instanceof \App\Models\User ? $actor->email : 'system',
            ],
            tags: 'backoffice,cards,transactions',
        );

        return response()->streamDownload(function () use ($records): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new RuntimeException('Unable to open output stream for CSV export.');
            }
            fputcsv($handle, ['id', 'card_id', 'user_id', 'merchant_name', 'amount_cents', 'currency', 'status', 'authorised_at', 'settled_at']);

            foreach ($records as $tx) {
                /** @var CardTransaction $tx */
                fputcsv($handle, [
                    (string) $tx->id,
                    (string) $tx->card_id,
                    (string) $tx->user_id,
                    (string) ($tx->merchant_name ?? ''),
                    (string) $tx->amount_cents,
                    (string) $tx->currency,
                    (string) $tx->status,
                    $tx->created_at?->toIso8601String() ?? '',
                    $tx->settled_at?->toIso8601String() ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCardTransactions::route('/'),
            'view'  => Pages\ViewCardTransaction::route('/{record}'),
        ];
    }
}
