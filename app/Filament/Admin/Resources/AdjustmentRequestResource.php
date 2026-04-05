<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\AdjustmentRequest;
use App\Filament\Admin\Resources\AdjustmentRequestResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdjustmentRequestResource extends Resource
{
    protected static ?string $model = AdjustmentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-plus';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $modelLabel = 'Adjustment Request';

    protected static ?string $pluralModelLabel = 'Adjustment Requests';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('account_id')
                    ->label('Target Account')
                    ->relationship('account', 'id')
                    ->searchable()
                    ->required()
                    ->disabledOn('view'),
                Select::make('type')
                    ->label('Adjustment Type')
                    ->options([
                        'credit' => 'Credit (Add Funds)',
                        'debit'  => 'Debit (Deduct Funds)',
                    ])
                    ->required()
                    ->disabledOn('view'),
                TextInput::make('amount')
                    ->label('Amount')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->disabledOn('view'),
                Textarea::make('reason')
                    ->label('Business Reason')
                    ->required()
                    ->columnSpanFull()
                    ->disabledOn('view'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account.user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'credit' => 'success',
                        'debit'  => 'danger',
                        default  => 'secondary',
                    })
                    ->sortable(),
                TextColumn::make('amount')
                    ->money('USD') // Assuming USD for now or flexible based on currency
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'secondary',
                    })
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (AdjustmentRequest $record) => $record->status === 'pending')
                    ->action(function (AdjustmentRequest $record) {
                        $record->update([
                            'status'      => 'approved',
                            'reviewer_id' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);
                        // TODO: Dispatch domain event (e.g. AccountCredited/AccountDebited)
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (AdjustmentRequest $record) => $record->status === 'pending')
                    ->action(function (AdjustmentRequest $record) {
                        $record->update([
                            'status'      => 'rejected',
                            'reviewer_id' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAdjustmentRequests::route('/'),
            'create' => Pages\CreateAdjustmentRequest::route('/create'),
            'view'   => Pages\ViewAdjustmentRequest::route('/{record}'),
        ];
    }
}
