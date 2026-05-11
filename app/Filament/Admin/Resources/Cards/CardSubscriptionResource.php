<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardSubscriptions\Enums\CardFeeStatus;
use App\Domain\CardSubscriptions\Enums\CardFeeType;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardFee;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardBillingService;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Filament\Admin\Resources\Cards\CardSubscriptionResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CardSubscriptionResource extends Resource
{
    protected static ?string $model = CardSubscription::class;

    protected static ?string $navigationGroup = 'Cards';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subscriber.full_name')
                    ->label('Subscriber')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscriber.phone_number')
                    ->label('Phone Number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('payer.full_name')
                    ->label('Payer')
                    ->description(fn (CardSubscription $record): string => $record->is_minor_subscription ? 'Minor Subscription' : '')
                    ->searchable(),
                Tables\Columns\TextColumn::make('plan.code')
                    ->label('Plan Code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (CardSubscriptionStatus $state): string => match ($state) {
                        CardSubscriptionStatus::Active    => 'success',
                        CardSubscriptionStatus::PastDue   => 'warning',
                        CardSubscriptionStatus::Suspended => 'danger',
                        CardSubscriptionStatus::Cancelled => 'gray',
                        default                           => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('current_period_end')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('next_billing_date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('failed_payment_count')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(CardSubscriptionStatus::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('card_plan_id')
                    ->label('Plan Code')
                    ->options(fn (): array => CardPlan::query()->pluck('name', 'id')->all())
                    ->multiple(),
                Tables\Filters\TernaryFilter::make('is_minor_subscription')
                    ->label('Is Minor Subscription'),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from'),
                        Forms\Components\DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('retry_payment')
                    ->label('Retry Payment')
                    ->visible(fn (CardSubscription $record): bool => in_array($record->status, [CardSubscriptionStatus::PastDue, CardSubscriptionStatus::Suspended], true)
                        && Gate::allows('retryPayment', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (CardSubscription $record, array $data, CardBillingService $billing, CardAuditService $audit): void {
                        Gate::authorize('retryPayment', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $billing->retryFailedPayment($record);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'subscription.payment_retry',
                            entityType: CardSubscription::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']]
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('suspend')
                    ->label('Suspend (Admin)')
                    ->color('danger')
                    ->visible(fn (CardSubscription $record): bool => $record->status === CardSubscriptionStatus::Active
                        && Gate::allows('suspend', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (CardSubscription $record, array $data, CardSubscriptionService $service, CardAuditService $audit): void {
                        Gate::authorize('suspend', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $service->suspend($record);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'subscription.admin_suspended',
                            entityType: CardSubscription::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']]
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('reactivate')
                    ->label('Reactivate')
                    ->color('success')
                    ->visible(fn (CardSubscription $record): bool => $record->status === CardSubscriptionStatus::Suspended
                        && Gate::allows('reactivate', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (CardSubscription $record, array $data, CardSubscriptionService $service, CardAuditService $audit): void {
                        Gate::authorize('reactivate', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $service->restore($record);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'subscription.admin_reactivated',
                            entityType: CardSubscription::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']]
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('force_cancel')
                    ->label('Force Cancel')
                    ->color('danger')
                    ->visible(fn (CardSubscription $record): bool => $record->status !== CardSubscriptionStatus::Cancelled
                        && Gate::allows('forceCancel', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (CardSubscription $record, array $data, CardSubscriptionService $service, CardAuditService $audit): void {
                        Gate::authorize('forceCancel', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        /** @var \App\Models\User $subscriber */
                        $subscriber = $record->subscriber;
                        $service->cancel($subscriber);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'subscription.admin_cancelled',
                            entityType: CardSubscription::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']]
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('change_plan')
                    ->label('Change Plan')
                    ->visible(fn (CardSubscription $record): bool => in_array($record->status, [CardSubscriptionStatus::Active, CardSubscriptionStatus::PastDue], true)
                        && Gate::allows('changePlan', $record))
                    ->form([
                        Forms\Components\Select::make('new_plan_code')
                            ->label('New Plan')
                            ->options(fn (): array => CardPlan::query()->where('active', true)->pluck('name', 'code')->all())
                            ->required(),
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (CardSubscription $record, array $data, CardSubscriptionService $service, CardAuditService $audit): void {
                        Gate::authorize('changePlan', $record);
                        $newPlanCode = $data['new_plan_code'];
                        $oldPlanCode = $record->plan->code;
                        $oldPlan = $record->plan;
                        $newPlan = CardPlan::where('code', $newPlanCode)->firstOrFail();

                        /** @var \App\Models\User $subscriber */
                        $subscriber = $record->subscriber;
                        if ($newPlan->tier_level > $oldPlan->tier_level) {
                            $service->upgrade($subscriber, $newPlanCode);
                        } elseif ($newPlan->tier_level < $oldPlan->tier_level) {
                            $service->downgrade($subscriber, $newPlanCode, true);
                        }

                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'subscription.admin_plan_change',
                            entityType: CardSubscription::class,
                            entityId: (string) $record->id,
                            metadata: [
                                'admin_reason'  => $data['reason'],
                                'old_plan_code' => $oldPlanCode,
                                'new_plan_code' => $newPlanCode,
                            ]
                        );
                    })
                    ->requiresConfirmation(),
                Tables\Actions\Action::make('waive_next_month')
                    ->label('Waive Next Month')
                    ->visible(fn (CardSubscription $record): bool => $record->status === CardSubscriptionStatus::Active
                        && Gate::allows('waive', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (CardSubscription $record, array $data, CardAuditService $audit): void {
                        Gate::authorize('waive', $record);
                        CardFee::create([
                            'user_id'             => $record->payer_user_id,
                            'related_entity_id'   => $record->id,
                            'related_entity_type' => CardSubscription::class,
                            'fee_type'            => CardFeeType::Subscription,
                            'amount'              => (string) $record->plan->monthly_fee,
                            'currency'            => 'SZL',
                            'status'              => CardFeeStatus::Waived,
                            'waived_at'           => now(),
                            'notes'               => $data['reason'],
                        ]);

                        $record->next_billing_date = $record->next_billing_date !== null
                            ? \Illuminate\Support\Carbon::parse($record->next_billing_date)->addMonth()->toDateTimeString()
                            : now()->addMonth()->toDateTimeString();
                        $record->save();

                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'subscription.admin_waived',
                            entityType: CardSubscription::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']]
                        );
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCardSubscriptions::route('/'),
            'view'  => Pages\ViewCardSubscription::route('/{record}'),
        ];
    }
}
