<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorCardRequestService;
use App\Domain\Account\Services\MinorCardService;
use App\Filament\Admin\Resources\MinorCardRequestResource\Pages;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MinorCardRequestResource extends Resource
{
    protected static ?string $model = MinorCardRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Youth & family accounts';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Minor Card Request';

    protected static ?string $pluralModelLabel = 'Minor Card Requests';

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
            TextInput::make('minor_account_uuid')->disabled(),
            TextInput::make('requested_by_user_uuid')->label('Requested By')->disabled(),
            TextInput::make('request_type')->disabled(),
            TextInput::make('status')->disabled(),
            TextInput::make('requested_network')->disabled(),
            TextInput::make('requested_daily_limit')->label('Daily Limit')->disabled(),
            TextInput::make('requested_monthly_limit')->label('Monthly Limit')->disabled(),
            TextInput::make('requested_single_limit')->label('Single Transaction Limit')->disabled(),
            Textarea::make('denial_reason')->disabled(),
            TextInput::make('approved_by_user_uuid')->label('Approved By')->disabled(),
            TextInput::make('approved_at')->disabled(),
            TextInput::make('expires_at')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Request')
                    ->copyable()
                    ->searchable()
                    ->limit(12),
                TextColumn::make('minor_account_uuid')
                    ->label('Minor Account')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('request_type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED => 'Parent Initiated',
                        MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED  => 'Child Requested',
                        default                                           => $state,
                    }),
                BadgeColumn::make('status')
                    ->colors([
                        MinorCardConstants::STATUS_PENDING_APPROVAL => 'warning',
                        MinorCardConstants::STATUS_APPROVED         => 'success',
                        MinorCardConstants::STATUS_DENIED           => 'danger',
                        MinorCardConstants::STATUS_CARD_CREATED     => 'info',
                        MinorCardConstants::STATUS_EXPIRED          => 'gray',
                    ])
                    ->sortable(),
                TextColumn::make('requested_network')
                    ->badge(),
                TextColumn::make('requested_daily_limit')
                    ->label('Daily Limit')
                    ->money('USD'),
                TextColumn::make('requested_monthly_limit')
                    ->label('Monthly Limit')
                    ->money('USD'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->label('Expires At')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MinorCardConstants::STATUS_PENDING_APPROVAL => 'Pending Approval',
                        MinorCardConstants::STATUS_APPROVED         => 'Approved',
                        MinorCardConstants::STATUS_DENIED           => 'Denied',
                        MinorCardConstants::STATUS_CARD_CREATED     => 'Card Created',
                        MinorCardConstants::STATUS_EXPIRED          => 'Expired',
                    ]),
                Tables\Filters\SelectFilter::make('request_type')
                    ->options([
                        MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED => 'Parent Initiated',
                        MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED  => 'Child Requested',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Action::make('approve')
                    ->label('Approve Request')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MinorCardRequest $record): bool => $record->status === MinorCardConstants::STATUS_PENDING_APPROVAL)
                    ->action(function (MinorCardRequest $record): void {
                        $user = Auth::user();
                        if (! $user) {
                            return;
                        }

                        $minorAccount = $record->minorAccount;
                        if (! $minorAccount instanceof Account) {
                            return;
                        }

                        $accessService = app(MinorAccountAccessService::class);
                        $accessService->authorizeGuardian($user, $minorAccount);

                        $cardService = app(MinorCardService::class);
                        $cardService->createCardFromRequest($record);
                    }),
                Action::make('deny')
                    ->label('Deny Request')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')
                            ->label('Denial Reason')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500),
                    ])
                    ->visible(fn (MinorCardRequest $record): bool => $record->status === MinorCardConstants::STATUS_PENDING_APPROVAL)
                    ->action(function (MinorCardRequest $record, array $data): void {
                        $user = Auth::user();
                        if (! $user) {
                            return;
                        }

                        $minorAccount = $record->minorAccount;
                        if (! $minorAccount instanceof Account) {
                            return;
                        }

                        $accessService = app(MinorAccountAccessService::class);
                        $accessService->authorizeGuardian($user, $minorAccount);

                        $requestService = app(MinorCardRequestService::class);
                        $requestService->deny($user, $record, $data['reason']);
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
            'index' => Pages\ListMinorCardRequests::route('/'),
            'view'  => Pages\ViewMinorCardRequest::route('/{record}'),
        ];
    }
}
