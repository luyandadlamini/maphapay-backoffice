<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use App\Domain\Account\Exceptions\NotEnoughFunds;
use App\Domain\Mobile\Models\Pocket;
use App\Domain\Mobile\Services\PocketTransferService;
use App\Support\BankingDisplay;
use Exception;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PocketsRelationManager extends RelationManager
{
    protected static string $relationship = 'pockets';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Savings Pockets';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('target_amount')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('current_amount')
                    ->numeric(),
                Forms\Components\TextInput::make('category')
                    ->maxLength(50),
                Forms\Components\TextInput::make('color')
                    ->maxLength(7),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->sortable()
                    ->badge(),
                Tables\Columns\ViewColumn::make('progress_percentage')
                    ->label('Progress')
                    ->view('filament.tables.columns.pocket-progress'),
                Tables\Columns\TextColumn::make('current_amount')
                    ->label('Saved')
                    ->money(config('banking.default_currency', 'SZL'), 100)
                    ->sortable(),
                Tables\Columns\TextColumn::make('target_amount')
                    ->label('Target')
                    ->money(config('banking.default_currency', 'SZL'), 100)
                    ->sortable(),
                Tables\Columns\TextColumn::make('target_date')
                    ->label('Target Date')
                    ->date('M j, Y')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_completed')
                    ->label('Completed')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),
                Tables\Columns\ColorColumn::make('color')
                    ->label('Color'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'travel'    => 'Travel',
                        'transport' => 'Transport',
                        'tech'      => 'Tech',
                        'emergency' => 'Emergency',
                        'food'      => 'Food',
                        'health'    => 'Health',
                        'education' => 'Education',
                        'general'   => 'General',
                    ]),
                Tables\Filters\Filter::make('is_completed')
                    ->query(fn ($query) => $query->where('is_completed', true))
                    ->label('Completed'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('addFunds')
                    ->label('Add Funds')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01),
                    ])
                    ->action(function (Pocket $record, array $data): void {
                        try {
                            app(PocketTransferService::class)->transferToPocket(
                                user: $record->user,
                                pocket: $record,
                                amountMajor: (float) $data['amount'],
                            );
                            $record->refresh();

                            Notification::make()
                                ->title('Funds Added')
                                ->success()
                                ->body(BankingDisplay::majorUnitsAsString((float) $data['amount']) . ' added to ' . $record->name)
                                ->send();
                        } catch (NotEnoughFunds) {
                            Notification::make()
                                ->title('Failed to Add Funds')
                                ->danger()
                                ->body('Insufficient balance in wallet.')
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Failed to Add Funds')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('withdrawFunds')
                    ->label('Withdraw')
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01),
                    ])
                    ->action(function (Pocket $record, array $data): void {
                        try {
                            app(PocketTransferService::class)->transferFromPocket(
                                user: $record->user,
                                pocket: $record,
                                amountMajor: (float) $data['amount'],
                            );
                            $record->refresh();

                            Notification::make()
                                ->title('Funds Withdrawn')
                                ->success()
                                ->body(BankingDisplay::majorUnitsAsString((float) $data['amount']) . ' withdrawn from ' . $record->name)
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Failed to Withdraw Funds')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }
}
