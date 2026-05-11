<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardLifecycleService;
use App\Domain\CardSubscriptions\ValueObjects\ReplacementReason;
use App\Filament\Admin\Resources\Cards\CardResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CardResource extends Resource
{
    protected static ?string $model = Card::class;

    protected static ?string $navigationGroup = 'Cards';

    protected static ?string $navigationLabel = 'Cards';

    protected static ?int $navigationSort = 11;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('last4')
                    ->label('Last 4')
                    ->searchable()
                    ->copyable()
                    ->formatStateUsing(fn (string $state): string => '**** ' . $state),
                Tables\Columns\TextColumn::make('user.full_name')
                    ->label('Cardholder')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'virtual'  => 'info',
                        'physical' => 'warning',
                        default    => 'gray',
                    }),
                Tables\Columns\TextColumn::make('tier')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'          => 'success',
                        'frozen'          => 'warning',
                        'frozen_by_admin' => 'danger',
                        'cancelled', 'expired' => 'gray',
                        default => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('subscription.plan.code')
                    ->label('Plan')
                    ->default('—'),
                Tables\Columns\IconColumn::make('online_enabled')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('international_enabled')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active'          => 'Active',
                        'frozen'          => 'Frozen (User)',
                        'frozen_by_admin' => 'Frozen (Admin)',
                        'cancelled'       => 'Cancelled',
                        'expired'         => 'Expired',
                    ])
                    ->multiple(),
                Tables\Filters\SelectFilter::make('kind')
                    ->label('Card Type')
                    ->options([
                        'virtual'  => 'Virtual',
                        'physical' => 'Physical',
                    ]),
                Tables\Filters\SelectFilter::make('card_plan_id')
                    ->label('Plan')
                    ->relationship('subscription.plan', 'code')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from'),
                        Forms\Components\DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('admin_freeze')
                    ->label('Freeze (Admin)')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (Card $record): bool => $record->status === 'active' && Gate::allows('adminFreeze', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500),
                    ])
                    ->action(function (Card $record, array $data, CardLifecycleService $lifecycle, CardAuditService $audit): void {
                        Gate::authorize('adminFreeze', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $lifecycle->adminFreeze($admin, $record, $data['reason']);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'card.admin_frozen',
                            entityType: Card::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']],
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('admin_unfreeze')
                    ->label('Unfreeze (Admin)')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn (Card $record): bool => $record->status === 'frozen_by_admin' && Gate::allows('adminUnfreeze', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500),
                    ])
                    ->action(function (Card $record, array $data, CardLifecycleService $lifecycle, CardAuditService $audit): void {
                        Gate::authorize('adminUnfreeze', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $lifecycle->adminUnfreeze($admin, $record, $data['reason']);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'card.admin_unfrozen',
                            entityType: Card::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']],
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('mark_lost_stolen')
                    ->label('Mark Lost/Stolen')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (Card $record): bool => in_array($record->status, ['active', 'frozen', 'frozen_by_admin'], true)
                        && Gate::allows('markLostStolen', $record))
                    ->form([
                        Forms\Components\Select::make('replacement_reason')
                            ->label('Reason')
                            ->options([
                                ReplacementReason::LOST->value   => 'Lost',
                                ReplacementReason::STOLEN->value => 'Stolen',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('reason')
                            ->label('Additional Notes')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500),
                    ])
                    ->action(function (Card $record, array $data, CardLifecycleService $lifecycle, CardAuditService $audit): void {
                        Gate::authorize('markLostStolen', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $replacementReason = ReplacementReason::from($data['replacement_reason']);
                        $lifecycle->replaceCard($admin, $record, $replacementReason);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'card.lost_stolen_marked',
                            entityType: Card::class,
                            entityId: (string) $record->id,
                            metadata: [
                                'admin_reason'       => $data['reason'],
                                'replacement_reason' => $data['replacement_reason'],
                            ],
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('admin_cancel')
                    ->label('Cancel (Admin)')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Card $record): bool => ! in_array($record->status, ['cancelled', 'expired'], true)
                        && Gate::allows('adminCancel', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500),
                    ])
                    ->action(function (Card $record, array $data, CardLifecycleService $lifecycle, CardAuditService $audit): void {
                        Gate::authorize('adminCancel', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $lifecycle->cancelCard($admin, $record, $data['reason']);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'card.admin_cancelled',
                            entityType: Card::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']],
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('view_transactions')
                    ->label('View Transactions')
                    ->icon('heroicon-o-banknotes')
                    ->url(fn (Card $record): string => CardTransactionResource::getUrl('index', ['tableFilters' => ['card_id' => $record->id]]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('view_audit_trail')
                    ->label('View Audit Trail')
                    ->icon('heroicon-o-shield-check')
                    ->url(fn (Card $record): string => CardAuditLogResource::getUrl('index', ['tableFilters' => ['entity_id' => $record->id]]))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCards::route('/'),
            'view'  => Pages\ViewCard::route('/{record}'),
        ];
    }
}
