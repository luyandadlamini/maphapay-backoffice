<?php

namespace App\Filament\Admin\Resources\GlobalTransactionResource\Pages;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Filament\Admin\Resources\GlobalTransactionResource;
use App\Filament\Admin\Resources\UserResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewGlobalTransaction extends ViewRecord
{
    protected static string $resource = GlobalTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No edit actions for transactions — read-only view
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Transaction Overview')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('trx')
                        ->label('Transaction ID')
                        ->copyable()
                        ->copyMessage('Copied!')
                        ->weight('bold'),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            AuthorizedTransaction::STATUS_COMPLETED => 'success',
                            AuthorizedTransaction::STATUS_PENDING   => 'warning',
                            AuthorizedTransaction::STATUS_FAILED,
                            AuthorizedTransaction::STATUS_CANCELLED,
                            AuthorizedTransaction::STATUS_EXPIRED   => 'danger',
                            default                                 => 'secondary',
                        }),
                    TextEntry::make('remark')
                        ->label('Transaction Type')
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            AuthorizedTransaction::REMARK_SEND_MONEY             => 'Send Money',
                            AuthorizedTransaction::REMARK_SCHEDULED_SEND         => 'Scheduled Send',
                            AuthorizedTransaction::REMARK_REQUEST_MONEY          => 'Request Money',
                            AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED => 'Request Money Received',
                            default                                               => ucwords(str_replace('_', ' ', $state)),
                        }),
                ]),
                Grid::make(3)->schema([
                    TextEntry::make('user.name')
                        ->label('Customer')
                        ->url(fn ($record) => $record->user_id
                            ? UserResource::getUrl('view', ['record' => $record->user_id])
                            : null)
                        ->color('primary'),
                    TextEntry::make('user.email')
                        ->label('Customer Email'),
                    TextEntry::make('verification_type')
                        ->label('Verification Method')
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            AuthorizedTransaction::VERIFICATION_OTP  => 'OTP',
                            AuthorizedTransaction::VERIFICATION_PIN  => 'PIN',
                            AuthorizedTransaction::VERIFICATION_NONE => 'None (bypass)',
                            null                                     => '—',
                            default                                  => $state,
                        }),
                ]),
            ]),

            Section::make('Timeline')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('created_at')
                        ->label('Initiated At')
                        ->dateTime('M j, Y H:i:s'),
                    TextEntry::make('verification_confirmed_at')
                        ->label('Verified At')
                        ->dateTime('M j, Y H:i:s')
                        ->placeholder('Not yet verified'),
                    TextEntry::make('expires_at')
                        ->label('Expires At')
                        ->dateTime('M j, Y H:i:s')
                        ->placeholder('No expiry')
                        ->color(fn ($record) => $record?->isExpired() ? 'danger' : null),
                ]),
                Grid::make(2)->schema([
                    TextEntry::make('failure_reason')
                        ->label('Failure Reason')
                        ->placeholder('None')
                        ->visible(fn ($record) => filled($record?->failure_reason)),
                    TextEntry::make('verification_failures')
                        ->label('Verification Failures')
                        ->suffix(' attempts'),
                ]),
            ]),

            Section::make('Payload')->schema([
                KeyValueEntry::make('payload')
                    ->label('Request Payload'),
            ])->collapsible(),

            Section::make('Result')->schema([
                KeyValueEntry::make('result')
                    ->label('Response Result'),
            ])->collapsible(),
        ]);
    }
}
