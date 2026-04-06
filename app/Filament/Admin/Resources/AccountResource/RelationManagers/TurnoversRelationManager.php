<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TurnoversRelationManager extends RelationManager
{
    protected static string $relationship = 'turnovers';

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $title = 'Account Turnovers';

    public function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('id')
                        ->label('ID')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('debit')
                        ->label('Total Debit')
                        ->money('USD', 100)
                        ->color('danger')
                        ->weight('bold'),
                    Tables\Columns\TextColumn::make('credit')
                        ->label('Total Credit')
                        ->money('USD', 100)
                        ->color('success')
                        ->weight('bold'),
                    Tables\Columns\TextColumn::make('net_turnover')
                        ->label('Net Turnover')
                        ->money('USD', 100)
                        ->getStateUsing(fn ($record) => $record->credit - $record->debit)
                        ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger')
                        ->weight('bold'),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Period')
                        ->dateTime('M Y')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('updated_at')
                        ->label('Last Updated')
                        ->dateTime('M j, Y g:i A')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ]
            )
            ->defaultSort('created_at', 'desc')
            ->filters(
                [
                    Tables\Filters\Filter::make('created_at')
                        ->form(
                            [
                                Forms\Components\DatePicker::make('created_from')
                                    ->label('From'),
                                Forms\Components\DatePicker::make('created_until')
                                    ->label('Until'),
                            ]
                        )
                        ->query(
                            function (Builder $query, array $data): Builder {
                                return $query
                                    ->when(
                                        $data['created_from'],
                                        fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                                    )
                                    ->when(
                                        $data['created_until'],
                                        fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                                    );
                            }
                        )
                        ->indicateUsing(
                            function (array $data): array {
                                $indicators = [];
                                if ($data['created_from'] ?? null) {
                                    $indicators['created_from'] = 'From ' . \Carbon\Carbon::parse($data['created_from'])->toFormattedDateString();
                                }
                                if ($data['created_until'] ?? null) {
                                    $indicators['created_until'] = 'Until ' . \Carbon\Carbon::parse($data['created_until'])->toFormattedDateString();
                                }

                                return $indicators;
                            }
                        ),
                ]
            )
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
