<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\RelationManagers;

use App\Domain\Banking\Models\BankAccountModel;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinkedWalletsRelationManager extends RelationManager
{
    protected static string $relationship = 'linkedWallets';

    protected static ?string $recordTitleAttribute = 'bank_code';

    protected static ?string $title = 'Linked External Wallets & Banks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_code')
                    ->label('Bank/Provider')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('account_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('masked_account')
                    ->label('Account/Phone')
                    ->getStateUsing(fn (BankAccountModel $record) => $record->account_number ? $record->getMaskedAccountNumber() : null),
                TextColumn::make('currency'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'   => 'success',
                        'inactive' => 'danger',
                        default    => 'secondary',
                    }),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('unlink')
                    ->label('Unlink')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Unlink Wallet')
                    ->modalDescription('Are you sure you want to unlink this wallet? This action will remove the link from the customer account.')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for unlinking')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (BankAccountModel $record, array $data): void {
                        $metadata = $record->metadata ?? [];
                        $metadata['unlinked_reason'] = $data['reason'];
                        $metadata['unlinked_by'] = auth()->id();
                        $metadata['unlinked_at'] = now()->toIso8601String();

                        $record->update([
                            'status'   => 'inactive',
                            'metadata' => $metadata,
                        ]);

                        if (function_exists('activity')) {
                            activity()
                                ->performedOn($record)
                                ->causedBy(auth()->user())
                                ->withProperties(['reason' => $data['reason']])
                                ->log('unlinked_wallet');
                        }
                    }),
            ])
            ->bulkActions([]);
    }
}
