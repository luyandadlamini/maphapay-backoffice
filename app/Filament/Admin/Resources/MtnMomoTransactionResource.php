<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Filament\Admin\Resources\MtnMomoTransactionResource\Pages;
use App\Models\MtnMomoTransaction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MtnMomoTransactionResource extends Resource
{
    protected static ?string $model = MtnMomoTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $modelLabel = 'Mtn MoMo Transaction';

    protected static ?string $pluralModelLabel = 'Mtn MoMo Transactions';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', MtnMomoTransaction::STATUS_FAILED)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::where('status', MtnMomoTransaction::STATUS_FAILED)->count() > 0 ? 'danger' : 'primary';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-transactions') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('idempotency_key')->disabled(),
                TextInput::make('type')->disabled(),
                TextInput::make('amount')->disabled(),
                TextInput::make('currency')->disabled(),
                TextInput::make('status')->disabled(),
                TextInput::make('party_msisdn')->disabled(),
                TextInput::make('mtn_reference_id')->disabled(),
                TextInput::make('mtn_financial_transaction_id')->disabled(),
                TextInput::make('context_type')->disabled(),
                TextInput::make('context_uuid')->disabled(),
                TextInput::make('note')->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('amount')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        MtnMomoTransaction::STATUS_SUCCESSFUL => 'success',
                        MtnMomoTransaction::STATUS_PENDING    => 'warning',
                        MtnMomoTransaction::STATUS_FAILED     => 'danger',
                        default                               => 'secondary',
                    })
                    ->sortable(),
                TextColumn::make('party_msisdn')
                    ->label('Mobile Number')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('context_type')
                    ->label('Context Type')
                    ->toggleable(),
                TextColumn::make('context_uuid')
                    ->label('Context UUID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MtnMomoTransaction::STATUS_SUCCESSFUL => 'Successful',
                        MtnMomoTransaction::STATUS_PENDING    => 'Pending',
                        MtnMomoTransaction::STATUS_FAILED     => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('view_linked_context')
                    ->label(fn (MtnMomoTransaction $record): string => static::getLinkedContextLabel($record))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (MtnMomoTransaction $record): ?string => static::getLinkedContextUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (MtnMomoTransaction $record): bool => static::getLinkedContextUrl($record) !== null),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMtnMomoTransactions::route('/'),
            'view'  => Pages\ViewMtnMomoTransaction::route('/{record}'),
        ];
    }

    private static function getLinkedContextLabel(MtnMomoTransaction $record): string
    {
        $linkedUrl = static::getLinkedContextUrl($record);

        if ($linkedUrl === null) {
            return 'Linked Context';
        }

        $supportTransfer = static::resolveSupportTransfer($record);
        if ($supportTransfer !== null) {
            return 'View Support Transfer';
        }

        $fundingAttempt = static::resolveFundingAttempt($record);
        if ($fundingAttempt !== null) {
            return 'View Funding Attempt';
        }

        return 'View Linked Context';
    }

    private static function getLinkedContextUrl(MtnMomoTransaction $record): ?string
    {
        $supportTransfer = static::resolveSupportTransfer($record);
        if ($supportTransfer !== null) {
            return MinorFamilySupportTransferResource::getUrl('view', ['record' => $supportTransfer->getKey()]);
        }

        $fundingAttempt = static::resolveFundingAttempt($record);
        if ($fundingAttempt !== null) {
            return MinorFamilyFundingAttemptResource::getUrl('view', ['record' => $fundingAttempt->getKey()]);
        }

        return null;
    }

    private static function resolveSupportTransfer(MtnMomoTransaction $record): ?MinorFamilySupportTransfer
    {
        $byTransaction = MinorFamilySupportTransfer::query()
            ->where('mtn_momo_transaction_id', $record->id)
            ->first();

        if ($byTransaction !== null) {
            return $byTransaction;
        }

        if ($record->context_uuid === null || $record->context_type === null) {
            return null;
        }

        if (in_array($record->context_type, [
            MinorFamilySupportTransfer::class,
            'minor_family_support_transfer',
            'support_transfer',
        ], true)) {
            /** @var MinorFamilySupportTransfer|null */
            return MinorFamilySupportTransfer::query()->find($record->context_uuid);
        }

        return null;
    }

    private static function resolveFundingAttempt(MtnMomoTransaction $record): ?MinorFamilyFundingAttempt
    {
        $byTransaction = MinorFamilyFundingAttempt::query()
            ->where('mtn_momo_transaction_id', $record->id)
            ->first();

        if ($byTransaction !== null) {
            return $byTransaction;
        }

        if ($record->context_uuid === null || $record->context_type === null) {
            return null;
        }

        if (in_array($record->context_type, [
            MinorFamilyFundingAttempt::class,
            'minor_family_funding_attempt',
            'funding_attempt',
        ], true)) {
            /** @var MinorFamilyFundingAttempt|null */
            return MinorFamilyFundingAttempt::query()->find($record->context_uuid);
        }

        return null;
    }
}
