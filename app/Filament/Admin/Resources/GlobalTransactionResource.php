<?php

namespace App\Filament\Admin\Resources;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Filament\Admin\Resources\GlobalTransactionResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GlobalTransactionResource extends Resource
{
    protected static ?string $model = AuthorizedTransaction::class;

    protected static ?string $modelLabel = 'Global Transaction';

    protected static ?string $pluralModelLabel = 'Global Transactions';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Transaction Details')->schema([
                    TextInput::make('trx')
                        ->label('Transaction Hash / ID')
                        ->disabled(),
                    TextInput::make('status')
                        ->label('Status')
                        ->disabled(),
                    TextInput::make('remark')
                        ->label('Type / Remark')
                        ->disabled(),
                    TextInput::make('user.name')
                        ->label('User')
                        ->disabled(),
                    TextInput::make('created_at')
                        ->label('Initiated At')
                        ->disabled(),
                ])->columns(2),
                Section::make('Payload & Result')->schema([
                    KeyValue::make('payload')
                        ->label('Payload Data')
                        ->disabled(),
                    KeyValue::make('result')
                        ->label('Result Data')
                        ->disabled(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('trx')
                    ->label('Tx Hash')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null),
                TextColumn::make('remark')
                    ->label('Remark')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'pending'   => 'warning',
                        'failed', 'cancelled', 'expired' => 'danger',
                        default => 'secondary',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Initiated At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        AuthorizedTransaction::STATUS_COMPLETED => 'Completed',
                        AuthorizedTransaction::STATUS_PENDING   => 'Pending',
                        AuthorizedTransaction::STATUS_FAILED    => 'Failed',
                        AuthorizedTransaction::STATUS_CANCELLED => 'Cancelled',
                        AuthorizedTransaction::STATUS_EXPIRED   => 'Expired',
                    ]),
                Tables\Filters\SelectFilter::make('remark')
                    ->label('Transaction Type')
                    ->options([
                        AuthorizedTransaction::REMARK_SEND_MONEY             => 'Send Money',
                        AuthorizedTransaction::REMARK_SCHEDULED_SEND         => 'Scheduled Send',
                        AuthorizedTransaction::REMARK_REQUEST_MONEY          => 'Request Money',
                        AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED => 'Request Money Received',
                    ]),
                Filter::make('created_at')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators['from'] = 'From ' . $data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $indicators['until'] = 'Until ' . $data['until'];
                        }
                        return $indicators;
                    }),
            ])
            ->filtersLayout(Tables\Enums\FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            // Can attach AuditLogRelationManager later if needed
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGlobalTransactions::route('/'),
            'view'  => Pages\ViewGlobalTransaction::route('/{record}'),
        ];
    }
}
