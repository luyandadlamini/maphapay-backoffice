<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardSubscriptions\Enums\PhysicalCardOrderStatus;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Filament\Admin\Resources\Cards\PhysicalCardOrderResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PhysicalCardOrderResource extends Resource
{
    protected static ?string $model = PhysicalCardOrder::class;

    protected static ?string $navigationGroup = 'Cards';

    protected static ?string $navigationLabel = 'Physical Orders';

    protected static ?int $navigationSort = 15;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.full_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivery_method')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'courier'    => 'info',
                        'collection' => 'warning',
                        default      => 'gray',
                    }),
                Tables\Columns\TextColumn::make('order_status')
                    ->badge()
                    ->color(fn (PhysicalCardOrderStatus $state): string => match ($state) {
                        PhysicalCardOrderStatus::Requested  => 'gray',
                        PhysicalCardOrderStatus::Paid       => 'info',
                        PhysicalCardOrderStatus::Approved   => 'primary',
                        PhysicalCardOrderStatus::Production => 'warning',
                        PhysicalCardOrderStatus::Dispatched, PhysicalCardOrderStatus::ReadyForCollection => 'success',
                        PhysicalCardOrderStatus::Delivered, PhysicalCardOrderStatus::Activated => 'success',
                        PhysicalCardOrderStatus::Cancelled => 'danger',
                    }),
                Tables\Columns\TextColumn::make('tracking_reference')
                    ->label('Tracking Ref')
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('requested_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dispatched_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('order_status')
                    ->options(PhysicalCardOrderStatus::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('delivery_method')
                    ->options(['courier' => 'Courier', 'collection' => 'Collection']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Requested → Approved
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (PhysicalCardOrder $record): bool => in_array($record->order_status, [PhysicalCardOrderStatus::Requested, PhysicalCardOrderStatus::Paid], true))
                    ->form([
                        Forms\Components\Textarea::make('reason')->required()->minLength(10),
                    ])
                    ->action(function (PhysicalCardOrder $record, array $data, CardAuditService $audit): void {
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['order_status' => PhysicalCardOrderStatus::Approved, 'approved_at' => now()]);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'physical_order.approved',
                            entityType: PhysicalCardOrder::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']],
                        );
                    })
                    ->requiresConfirmation(),
                // Requested → Rejected
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (PhysicalCardOrder $record): bool => $record->order_status === PhysicalCardOrderStatus::Requested)
                    ->form([
                        Forms\Components\Textarea::make('reason')->required()->minLength(10),
                    ])
                    ->action(function (PhysicalCardOrder $record, array $data, CardAuditService $audit): void {
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['order_status' => PhysicalCardOrderStatus::Cancelled]);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'physical_order.rejected',
                            entityType: PhysicalCardOrder::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']],
                        );
                    })
                    ->requiresConfirmation(),
                // Approved → Production
                Tables\Actions\Action::make('send_to_production')
                    ->label('Send to Production')
                    ->color('primary')
                    ->visible(fn (PhysicalCardOrder $record): bool => $record->order_status === PhysicalCardOrderStatus::Approved)
                    ->form([
                        Forms\Components\Textarea::make('reason')->required()->minLength(10),
                    ])
                    ->action(function (PhysicalCardOrder $record, array $data, CardAuditService $audit): void {
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['order_status' => PhysicalCardOrderStatus::Production]);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'physical_order.sent_to_production',
                            entityType: PhysicalCardOrder::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']],
                        );
                    })
                    ->requiresConfirmation(),
                // Production → Dispatched
                Tables\Actions\Action::make('mark_dispatched')
                    ->label('Mark Dispatched')
                    ->color('success')
                    ->visible(fn (PhysicalCardOrder $record): bool => $record->order_status === PhysicalCardOrderStatus::Production)
                    ->form([
                        Forms\Components\TextInput::make('tracking_reference')->required()->maxLength(100),
                        Forms\Components\Select::make('courier')
                            ->options(['dawn' => 'Dawn Wing', 'dhl' => 'DHL', 'fastway' => 'Fastway', 'other' => 'Other'])
                            ->required(),
                        Forms\Components\Textarea::make('reason')->required()->minLength(10),
                    ])
                    ->action(function (PhysicalCardOrder $record, array $data, CardAuditService $audit): void {
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update([
                            'order_status'       => PhysicalCardOrderStatus::Dispatched,
                            'tracking_reference' => $data['tracking_reference'],
                            'dispatched_at'      => now(),
                        ]);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'physical_order.dispatched',
                            entityType: PhysicalCardOrder::class,
                            entityId: (string) $record->id,
                            metadata: [
                                'admin_reason'       => $data['reason'],
                                'tracking_reference' => $data['tracking_reference'],
                                'courier'            => $data['courier'],
                            ],
                        );
                    })
                    ->requiresConfirmation(),
                // Production → Ready for Collection
                Tables\Actions\Action::make('mark_ready_for_collection')
                    ->label('Ready for Collection')
                    ->color('success')
                    ->visible(fn (PhysicalCardOrder $record): bool => $record->order_status === PhysicalCardOrderStatus::Production)
                    ->form([
                        Forms\Components\Textarea::make('reason')->required()->minLength(10),
                    ])
                    ->action(function (PhysicalCardOrder $record, array $data, CardAuditService $audit): void {
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['order_status' => PhysicalCardOrderStatus::ReadyForCollection]);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'physical_order.ready_for_collection',
                            entityType: PhysicalCardOrder::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['reason']],
                        );
                    })
                    ->requiresConfirmation(),
                // Dispatched / Ready → Delivered
                Tables\Actions\Action::make('mark_delivered')
                    ->label('Mark Delivered')
                    ->color('success')
                    ->visible(fn (PhysicalCardOrder $record): bool => in_array($record->order_status, [PhysicalCardOrderStatus::Dispatched, PhysicalCardOrderStatus::ReadyForCollection], true))
                    ->form([
                        Forms\Components\Textarea::make('confirmation_note')
                            ->label('Confirmation Note')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (PhysicalCardOrder $record, array $data, CardAuditService $audit): void {
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['order_status' => PhysicalCardOrderStatus::Delivered]);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'physical_order.delivered',
                            entityType: PhysicalCardOrder::class,
                            entityId: (string) $record->id,
                            metadata: ['admin_reason' => $data['confirmation_note']],
                        );
                    })
                    ->requiresConfirmation(),
                // Cancel (non-terminal)
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->visible(fn (PhysicalCardOrder $record): bool => ! in_array($record->order_status, [PhysicalCardOrderStatus::Cancelled, PhysicalCardOrderStatus::Activated, PhysicalCardOrderStatus::Delivered], true))
                    ->form([
                        Forms\Components\Select::make('issue_refund')
                            ->label('Issue Refund?')
                            ->options(['yes' => 'Yes', 'no' => 'No'])
                            ->required(),
                        Forms\Components\Textarea::make('reason')->required()->minLength(10),
                    ])
                    ->action(function (PhysicalCardOrder $record, array $data, CardAuditService $audit): void {
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['order_status' => PhysicalCardOrderStatus::Cancelled]);
                        $audit->recordAdminAction(
                            admin: $admin,
                            action: 'physical_order.cancelled',
                            entityType: PhysicalCardOrder::class,
                            entityId: (string) $record->id,
                            metadata: [
                                'admin_reason' => $data['reason'],
                                'issue_refund' => $data['issue_refund'],
                            ],
                        );
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPhysicalCardOrders::route('/'),
            'view'  => Pages\ViewPhysicalCardOrder::route('/{record}'),
        ];
    }
}
