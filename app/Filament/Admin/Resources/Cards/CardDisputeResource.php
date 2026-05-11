<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardSubscriptions\Enums\CardDisputeStatus;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardTransaction;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardFeeService;
use App\Filament\Admin\Resources\Cards\CardDisputeResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class CardDisputeResource extends Resource
{
    protected static ?string $model = CardDispute::class;

    protected static ?string $navigationGroup = 'Cards';

    protected static ?string $navigationLabel = 'Disputes';

    protected static ?int $navigationSort = 13;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('card_transaction_id')
                ->label('Transaction')
                ->options(function (): array {
                    return CardTransaction::query()
                        ->orderByDesc('id')
                        ->limit(500)
                        ->get()
                        ->mapWithKeys(fn (CardTransaction $record): array => [
                            (string) $record->id => $record->merchant_name . ' — '
                                . number_format($record->amount_cents / 100, 2) . ' ' . $record->currency,
                        ])
                        ->all();
                })
                ->searchable()
                ->preload()
                ->required(),
            Forms\Components\Select::make('reason')
                ->options(\App\Domain\CardSubscriptions\Enums\CardDisputeReason::class)
                ->required(),
            Forms\Components\Textarea::make('user_description')
                ->label('Customer Description')
                ->required()
                ->maxLength(1000),
            Forms\Components\TextInput::make('disputed_amount')
                ->numeric()
                ->step('0.01')
                ->required(),
            Forms\Components\Select::make('currency')
                ->options(['SZL' => 'SZL', 'ZAR' => 'ZAR', 'USD' => 'USD'])
                ->default('SZL')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.full_name')->label('Customer')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('card_transaction_id')
                    ->label('Merchant')
                    ->formatStateUsing(function ($state): string {
                        if ($state === null || $state === '') {
                            return '—';
                        }
                        /** @var CardTransaction|null $tx */
                        $tx = CardTransaction::query()->find($state);

                        return $tx instanceof CardTransaction ? $tx->merchant_name : '—';
                    }),
                Tables\Columns\TextColumn::make('reason')->badge(),
                Tables\Columns\TextColumn::make('disputed_amount')->numeric(2)->sortable(),
                Tables\Columns\TextColumn::make('currency'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (CardDisputeStatus $state): string => match ($state) {
                        CardDisputeStatus::Submitted        => 'info',
                        CardDisputeStatus::InReview         => 'warning',
                        CardDisputeStatus::EvidenceRequired => 'primary',
                        CardDisputeStatus::Won              => 'success',
                        CardDisputeStatus::Lost             => 'danger',
                        CardDisputeStatus::Withdrawn        => 'gray',
                    }),
                Tables\Columns\TextColumn::make('processor_dispute_id')->label('Processor Ref')->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(CardDisputeStatus::class)->multiple(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('mark_in_review')->label('Mark In Review')->color('warning')
                    ->visible(fn (CardDispute $r): bool => $r->status === CardDisputeStatus::Submitted
                        && Gate::allows('update', $r))
                    ->action(function (CardDispute $record, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['status' => CardDisputeStatus::InReview]);
                        $audit->recordAdminAction($admin, 'dispute.in_review', CardDispute::class, (string) $record->id, []);
                    }),
                Tables\Actions\Action::make('request_evidence')->label('Request Evidence')->color('primary')
                    ->visible(fn (CardDispute $r): bool => in_array($r->status, [CardDisputeStatus::InReview, CardDisputeStatus::Submitted], true)
                        && Gate::allows('update', $r))
                    ->form([Forms\Components\Textarea::make('evidence_request')->required()->minLength(20)])
                    ->action(function (CardDispute $record, array $data, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['status' => CardDisputeStatus::EvidenceRequired]);
                        $audit->recordAdminAction(
                            $admin,
                            'dispute.evidence_requested',
                            CardDispute::class,
                            (string) $record->id,
                            ['evidence_request' => $data['evidence_request']]
                        );
                    })->requiresConfirmation(),
                Tables\Actions\Action::make('mark_won')->label('Mark Won')->color('success')
                    ->visible(fn (CardDispute $r): bool => in_array($r->status, [CardDisputeStatus::InReview, CardDisputeStatus::EvidenceRequired], true)
                        && Gate::allows('update', $r))
                    ->form([Forms\Components\Textarea::make('resolution_notes')->required()->minLength(20)])
                    ->action(function (CardDispute $record, array $data, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['status' => CardDisputeStatus::Won, 'resolved_at' => now(), 'resolution_notes' => $data['resolution_notes']]);
                        $audit->recordAdminAction(
                            $admin,
                            'dispute.won',
                            CardDispute::class,
                            (string) $record->id,
                            ['resolution_notes' => $data['resolution_notes']]
                        );
                    })->requiresConfirmation(),
                Tables\Actions\Action::make('mark_lost')->label('Mark Lost')->color('danger')
                    ->visible(fn (CardDispute $r): bool => in_array($r->status, [CardDisputeStatus::InReview, CardDisputeStatus::EvidenceRequired], true)
                        && Gate::allows('update', $r))
                    ->form([
                        Forms\Components\Textarea::make('resolution_notes')->required()->minLength(20),
                        Forms\Components\Toggle::make('charge_abuse_fee')->label('Charge chargeback abuse fee?')->default(false),
                    ])
                    ->action(function (CardDispute $record, array $data, CardFeeService $feeService, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['status' => CardDisputeStatus::Lost, 'resolved_at' => now(), 'resolution_notes' => $data['resolution_notes']]);
                        if ($data['charge_abuse_fee']) {
                            /** @var \App\Models\User $disputeUser */
                            $disputeUser = $record->user;
                            $feeService->chargeChargebackAbuseFee($disputeUser, $record);
                        }
                        $audit->recordAdminAction(
                            $admin,
                            'dispute.lost',
                            CardDispute::class,
                            (string) $record->id,
                            ['resolution_notes' => $data['resolution_notes'], 'abuse_fee_charged' => $data['charge_abuse_fee']]
                        );
                    })->requiresConfirmation(),
                Tables\Actions\Action::make('mark_withdrawn')->label('Mark Withdrawn')->color('gray')
                    ->visible(fn (CardDispute $r): bool => ! in_array($r->status, [CardDisputeStatus::Won, CardDisputeStatus::Lost, CardDisputeStatus::Withdrawn], true)
                        && Gate::allows('update', $r))
                    ->form([Forms\Components\Textarea::make('resolution_notes')->required()->minLength(10)])
                    ->action(function (CardDispute $record, array $data, CardAuditService $audit): void {
                        Gate::authorize('update', $record);
                        /** @var \App\Models\User $admin */
                        $admin = auth()->user();
                        $record->update(['status' => CardDisputeStatus::Withdrawn, 'resolved_at' => now(), 'resolution_notes' => $data['resolution_notes']]);
                        $audit->recordAdminAction(
                            $admin,
                            'dispute.withdrawn',
                            CardDispute::class,
                            (string) $record->id,
                            ['resolution_notes' => $data['resolution_notes']]
                        );
                    })->requiresConfirmation(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCardDisputes::route('/'),
            'view'   => Pages\ViewCardDispute::route('/{record}'),
            'create' => Pages\CreateCardDispute::route('/create'),
        ];
    }
}
