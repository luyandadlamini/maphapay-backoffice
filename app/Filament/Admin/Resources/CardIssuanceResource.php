<?php

namespace App\Filament\Admin\Resources;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Filament\Admin\Resources\CardIssuanceResource\Pages;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Exception;

class CardIssuanceResource extends Resource
{
    protected static ?string $model = Card::class;

    protected static ?string $modelLabel = 'Issued Card';

    protected static ?string $pluralModelLabel = 'Issued Cards';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Wallets & Ledgers';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-cards') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Cards are managed via mobile app, admin is mostly read-only with block/reissue actions
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('masked_number')
                    ->label('Card Number')
                    ->getStateUsing(fn (Card $record) => $record->getMaskedNumber())
                    ->searchable(['last4']),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'frozen', 'suspended' => 'warning',
                        'cancelled', 'closed' => 'danger',
                        default => 'secondary',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Cardholder')
                    ->url(fn (Card $record) => UserResource::getUrl('view', ['record' => $record->user_id]))
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Issued At')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'frozen' => 'Frozen',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('block_card')
                    ->label('Block Card')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Card $record) => auth()->user()->can('manage-cards') && $record->isActive())
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for blocking')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (Card $record, array $data, CardProvisioningService $service): void {
                        try {
                            $service->freezeCard($record->issuer_card_token);
                            
                            if (function_exists('activity')) {
                                activity()
                                    ->performedOn($record)
                                    ->causedBy(auth()->user())
                                    ->withProperties(['reason' => $data['reason']])
                                    ->log('card_blocked');
                            }
                            
                            Notification::make()
                                ->title('Card blocked successfully')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Failed to block card')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('reissue_card')
                    ->label('Re-issue Card')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Re-issue Card')
                    ->modalDescription('This will cancel the current card and issue a new one. Are you sure?')
                    ->visible(fn (Card $record) => auth()->user()->can('manage-cards') && ($record->isActive() || $record->isFrozen()))
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for re-issuance')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (Card $record, array $data, CardProvisioningService $service): void {
                        try {
                            $service->cancelCard($record->issuer_card_token, $data['reason']);
                            
                            $newCard = $service->createCard(
                                $record->user_id,
                                $record->user->name,
                                ['reissued_from' => $record->id, 'reason' => $data['reason']],
                                \App\Domain\CardIssuance\Enums\CardNetwork::tryFrom($record->network)
                            );
                            
                            if (function_exists('activity')) {
                                activity()
                                    ->performedOn($record)
                                    ->causedBy(auth()->user())
                                    ->withProperties(['reason' => $data['reason'], 'new_card_token' => $newCard->cardToken])
                                    ->log('card_reissued');
                            }
                            
                            Notification::make()
                                ->title('Card cancelled and re-issued')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Failed to re-issue card')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('view_transactions')
                    ->label('View Transactions')
                    ->icon('heroicon-o-bars-3')
                    ->url(fn (Card $record) => GlobalTransactionResource::getUrl('index', ['tableSearchQuery' => $record->last4]))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([]);
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
            'index' => Pages\ListCardIssuances::route('/'),
        ];
    }
}
