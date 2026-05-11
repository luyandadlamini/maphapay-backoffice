<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardRiskEventStatus;
use App\Domain\CardSubscriptions\Enums\CardRiskSeverity;
use App\Domain\CardSubscriptions\Models\CardRiskEvent;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardLifecycleService;
use App\Filament\Admin\Resources\Cards\CardRiskEventResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class CardRiskEventResource extends Resource
{
    protected static ?string $model = CardRiskEvent::class;

    protected static ?string $navigationGroup = 'Cards';

    protected static ?string $navigationLabel = 'Risk Events';

    protected static ?int $navigationSort = 14;

    public static function canCreate(): bool
    {
    return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->color(fn (CardRiskSeverity $state): string => match ($state) {
                        CardRiskSeverity::Critical => 'danger',
                        CardRiskSeverity::High     => 'warning',
                        CardRiskSeverity::Medium   => 'primary',
                        CardRiskSeverity::Low      => 'gray',
                    })->sortable(),
                Tables\Columns\TextColumn::make('event_type')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.full_name')->label('User')->searchable(),
                Tables\Columns\TextColumn::make('card.last4')->label('Card')
                    ->formatStateUsing(fn (?string $state): string => $state ? '**** ' . $state : '—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (CardRiskEventStatus $state): string => match ($state) {
                        CardRiskEventStatus::Open      => 'danger',
                        CardRiskEventStatus::InReview  => 'warning',
                        CardRiskEventStatus::Resolved  => 'success',
                        CardRiskEventStatus::Dismissed => 'gray',
                    })->sortable(),
                Tables\Columns\TextColumn::make('assignedToAdmin.name')->label('Assigned To')->placeholder('Unassigned'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('severity', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('severity')->options(CardRiskSeverity::class)->multiple(),
                Tables\Filters\SelectFilter::make('status')->options(CardRiskEventStatus::class)->multiple(),
                Tables\Filters\Filter::make('unassigned')->label('Unassigned only')
                    ->query(fn ($query) => $query->whereNull('assigned_to_admin_id'))->toggle(),
                Tables\Filters\Filter::make('assigned_to_me')->label('Assigned to me')
                    ->query(fn ($query) => $query->where('assigned_to_admin_id', auth()->id()))->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('assign_to_me')->label('Assign to Me')->icon('heroicon-o-user-plus')
                    ->visible(fn (CardRiskEvent $r): bool => $r->status === CardRiskEventStatus::Open && $r->assigned_to_admin_id === null
                        && Gate::allows('update', $r))
                    ->action(function (CardRiskEvent $record, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['assigned_to_admin_id' => $admin->id, 'status' => CardRiskEventStatus::InReview]);
                        $audit->recordAdminAction($admin, 'risk_event.assigned', CardRiskEvent::class, (string) $record->id, []);
                    }),
                Tables\Actions\Action::make('mark_in_review')->label('In Review')->color('warning')
                    ->visible(fn (CardRiskEvent $r): bool => $r->status === CardRiskEventStatus::Open
                        && Gate::allows('update', $r))
                    ->action(function (CardRiskEvent $record, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['status' => CardRiskEventStatus::InReview, 'assigned_to_admin_id' => $admin->id]);
                        $audit->recordAdminAction($admin, 'risk_event.in_review', CardRiskEvent::class, (string) $record->id, []);
                    }),
                Tables\Actions\Action::make('resolve')->label('Resolve')->color('success')
                    ->visible(fn (CardRiskEvent $r): bool => in_array($r->status, [CardRiskEventStatus::Open, CardRiskEventStatus::InReview], true)
                        && Gate::allows('update', $r))
                    ->form([Forms\Components\Textarea::make('resolution_notes')->required()->minLength(20)])
                    ->action(function (CardRiskEvent $record, array $data, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['status' => CardRiskEventStatus::Resolved, 'resolved_at' => now(), 'resolution_notes' => $data['resolution_notes']]);
                        $audit->recordAdminAction($admin, 'risk_event.resolved', CardRiskEvent::class, (string) $record->id, ['resolution_notes' => $data['resolution_notes']]);
                    })->requiresConfirmation(),
                Tables\Actions\Action::make('dismiss')->label('Dismiss')->color('gray')
                    ->visible(fn (CardRiskEvent $r): bool => in_array($r->status, [CardRiskEventStatus::Open, CardRiskEventStatus::InReview], true)
                        && Gate::allows('update', $r))
                    ->form([Forms\Components\Textarea::make('reason')->required()->minLength(10)])
                    ->action(function (CardRiskEvent $record, array $data, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['status' => CardRiskEventStatus::Dismissed, 'resolved_at' => now(), 'resolution_notes' => $data['reason']]);
                        $audit->recordAdminAction($admin, 'risk_event.dismissed', CardRiskEvent::class, (string) $record->id, ['admin_reason' => $data['reason']]);
                    })->requiresConfirmation(),
                Tables\Actions\Action::make('freeze_card')->label('Freeze Related Card')->color('danger')
                    ->visible(function (CardRiskEvent $r): bool {
                        if ($r->card_id === null) {
                            return false;
                        }
                        $card = $r->card;
                        if (! $card instanceof Card) {
                            return false;
                        }

                        return Gate::allows('adminFreeze', $card);
                    })
                    ->form([Forms\Components\Textarea::make('reason')->required()->minLength(10)])
                    ->action(function (CardRiskEvent $record, array $data, CardLifecycleService $lifecycle, CardAuditService $audit): void {
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        /** @var Card $card */
                        $card = $record->card;
                        Gate::authorize('adminFreeze', $card);
                        $lifecycle->adminFreeze($admin, $card, $data['reason']);
                        $audit->recordAdminAction(
                            $admin,
                            'card.admin_frozen',
                            Card::class,
                            (string) $card->id,
                            ['admin_reason' => $data['reason'], 'triggered_by_risk_event_id' => $record->id]
                        );
                    })->requiresConfirmation(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCardRiskEvents::route('/'),
            'view'  => Pages\ViewCardRiskEvent::route('/{record}'),
        ];
    }
}
