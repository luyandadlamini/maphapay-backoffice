<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyReconciliationException;
use App\Domain\Account\Services\MinorFamilyReconciliationService;
use App\Filament\Admin\Resources\MinorFamilyFundingAttemptResource\Pages;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MinorFamilyFundingAttemptResource extends Resource
{
    protected static ?string $model = MinorFamilyFundingAttempt::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Youth & family accounts';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Minor Family Funding Attempt';

    protected static ?string $pluralModelLabel = 'Minor Family Funding Attempts';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-transactions') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('id')->label('UUID')->disabled(),
            TextInput::make('funding_link_uuid')->disabled(),
            TextInput::make('minor_account_uuid')->disabled(),
            TextInput::make('status')->disabled(),
            TextInput::make('provider_name')->disabled(),
            TextInput::make('provider_reference_id')->disabled(),
            TextInput::make('sponsor_name')->disabled(),
            TextInput::make('sponsor_msisdn')->disabled(),
            TextInput::make('amount')->disabled(),
            TextInput::make('asset_code')->disabled(),
            TextInput::make('wallet_credited_at')->disabled(),
            TextInput::make('failed_reason')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Attempt')
                    ->copyable()
                    ->searchable()
                    ->limit(12),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('sponsor_name')
                    ->searchable(),
                TextColumn::make('amount')
                    ->money(fn (MinorFamilyFundingAttempt $record): string => $record->asset_code),
                TextColumn::make('provider_name')
                    ->badge(),
                TextColumn::make('provider_reference_id')
                    ->label('Provider Reference')
                    ->toggleable(),
                TextColumn::make('wallet_credited_at')
                    ->label('Credited At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('failed_reason')
                    ->label('Reconciliation Context')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER         => 'Pending Provider',
                        MinorFamilyFundingAttempt::STATUS_SUCCESSFUL               => 'Successful',
                        MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED    => 'Successful Uncredited',
                        MinorFamilyFundingAttempt::STATUS_CREDITED                 => 'Credited',
                        MinorFamilyFundingAttempt::STATUS_FAILED                   => 'Failed',
                        MinorFamilyFundingAttempt::STATUS_EXPIRED                  => 'Expired',
                        MinorFamilyFundingAttempt::STATUS_EXPIRED_PROVIDER_PENDING => 'Expired Provider Pending',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Action::make('view_exception_artifact')
                    ->label('View Exception')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->url(fn (MinorFamilyFundingAttempt $record): ?string => static::resolveExceptionArtifactUrl($record))
                    ->visible(fn (MinorFamilyFundingAttempt $record): bool => static::resolveExceptionArtifactUrl($record) !== null),
                Action::make('retry_settlement')
                    ->label('Retry Settlement')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')
                            ->label('Operator Note')
                            ->maxLength(300)
                            ->placeholder('Capture why this retry was requested (optional).'),
                    ])
                    ->visible(fn (MinorFamilyFundingAttempt $record): bool => $record->status === MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED
                        && $record->mtnMomoTransaction !== null)
                    ->action(function (MinorFamilyFundingAttempt $record): void {
                        if ($record->mtnMomoTransaction === null) {
                            return;
                        }

                        app(MinorFamilyReconciliationService::class)->reconcile(
                            $record->mtnMomoTransaction,
                            'filament_retry_settlement',
                        );
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMinorFamilyFundingAttempts::route('/'),
            'view'  => Pages\ViewMinorFamilyFundingAttempt::route('/{record}'),
        ];
    }

    public static function resolveExceptionArtifactUrl(MinorFamilyFundingAttempt $record): ?string
    {
        if ($record->mtn_momo_transaction_id === null || $record->mtn_momo_transaction_id === '') {
            return null;
        }

        /** @var MinorFamilyReconciliationException|null $exception */
        $exception = MinorFamilyReconciliationException::query()
            ->where('mtn_momo_transaction_id', $record->mtn_momo_transaction_id)
            ->where('status', MinorFamilyReconciliationException::STATUS_OPEN)
            ->latest('last_seen_at')
            ->first();

        if ($exception === null) {
            return null;
        }

        return MinorFamilyReconciliationExceptionResource::getUrl('view', ['record' => $exception->getKey()]);
    }
}
