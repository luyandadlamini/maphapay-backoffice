<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Fraud\Models\AnomalyDetection;
use App\Filament\Admin\Resources\AnomalyDetectionResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AnomalyDetectionResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = AnomalyDetection::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Risk & Fraud';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Anomaly Alerts';

    public static function form(Form $form): Form
    {
        return $form
            ->schema(
                [
                    Forms\Components\Section::make('Alert Overview')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('id')
                                    ->label('Alert ID')
                                    ->disabled(),
                                Forms\Components\TextInput::make('anomaly_type')
                                    ->label('Anomaly Type')
                                    ->formatStateUsing(fn ($state) => $state->label())
                                    ->disabled(),
                                Forms\Components\TextInput::make('detection_method')
                                    ->label('Detection Method')
                                    ->formatStateUsing(fn ($state) => $state->label())
                                    ->disabled(),
                                Forms\Components\TextInput::make('status')
                                    ->formatStateUsing(fn ($state) => $state->label())
                                    ->disabled(),
                            ]
                        )->columns(4),

                    Forms\Components\Section::make('Scoring')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('anomaly_score')
                                    ->label('Anomaly Score')
                                    ->suffix('/100')
                                    ->disabled(),
                                Forms\Components\TextInput::make('confidence')
                                    ->label('Confidence')
                                    ->disabled(),
                                Forms\Components\TextInput::make('severity')
                                    ->formatStateUsing(fn ($state) => ucfirst((string) $state))
                                    ->disabled(),
                                Forms\Components\Toggle::make('is_real_time')
                                    ->label('Real-Time Detection')
                                    ->disabled(),
                            ]
                        )->columns(4),

                    Forms\Components\Section::make('Entity Details')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('entity_type')
                                    ->label('Entity Type')
                                    ->disabled(),
                                Forms\Components\TextInput::make('entity_id')
                                    ->label('Entity ID')
                                    ->disabled(),
                                Forms\Components\TextInput::make('model_version')
                                    ->label('Model Version')
                                    ->disabled(),
                                Forms\Components\TextInput::make('pipeline_run_id')
                                    ->label('Pipeline Run')
                                    ->disabled(),
                            ]
                        )->columns(4),

                    Forms\Components\Section::make('Review')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('feedback_outcome')
                                    ->label('Outcome')
                                    ->formatStateUsing(fn ($state) => $state ? ucfirst(str_replace('_', ' ', (string) $state)) : 'Pending')
                                    ->disabled(),
                                Forms\Components\Textarea::make('feedback_notes')
                                    ->label('Review Notes')
                                    ->disabled(),
                                Forms\Components\TextInput::make('reviewed_by')
                                    ->label('Reviewed By')
                                    ->disabled(),
                                Forms\Components\TextInput::make('reviewed_at')
                                    ->label('Reviewed At')
                                    ->disabled(),
                            ]
                        )->columns(2),
                ]
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Detected')
                        ->dateTime()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('anomaly_type')
                        ->label('Type')
                        ->formatStateUsing(fn ($state) => $state->label())
                        ->sortable(),
                    Tables\Columns\TextColumn::make('detection_method')
                        ->label('Method')
                        ->formatStateUsing(fn ($state) => $state->label())
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('anomaly_score')
                        ->label('Score')
                        ->numeric(1)
                        ->sortable()
                        ->color(
                            fn ($state): string => match (true) {
                                $state >= 80 => 'danger',
                                $state >= 60 => 'warning',
                                $state >= 40 => 'info',
                                default      => 'gray',
                            }
                        ),
                    Tables\Columns\TextColumn::make('severity')
                        ->badge()
                        ->formatStateUsing(fn ($state) => ucfirst((string) $state))
                        ->color(
                            fn (string $state): string => match ($state) {
                                'critical' => 'danger',
                                'high'     => 'warning',
                                'medium'   => 'info',
                                'low'      => 'gray',
                                default    => 'gray',
                            }
                        )
                        ->sortable(),
                    Tables\Columns\TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state->label())
                        ->color(
                            fn ($state): string => match ($state->value) {
                                'detected'       => 'danger',
                                'investigating'  => 'warning',
                                'confirmed'      => 'danger',
                                'false_positive' => 'gray',
                                'resolved'       => 'success',
                                default          => 'gray',
                            }
                        )
                        ->sortable(),
                    Tables\Columns\IconColumn::make('is_real_time')
                        ->label('RT')
                        ->boolean()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('user.name')
                        ->label('User')
                        ->searchable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('triage_status')
                        ->label('Triage')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ($state) {
                            'under_review'   => 'Under Review',
                            'escalated'      => 'Escalated',
                            'resolved'       => 'Resolved',
                            'false_positive' => 'False Positive',
                            default          => 'Detected',
                        })
                        ->color(fn ($state): string => match ((string) $state) {
                            'under_review'   => 'warning',
                            'escalated'      => 'danger',
                            'resolved'       => 'success',
                            'false_positive' => 'gray',
                            default          => 'danger',
                        })
                        ->sortable()
                        ->toggleable(),
                ]
            )
            ->defaultSort('created_at', 'desc')
            ->filters(
                [
                    Tables\Filters\SelectFilter::make('status')
                        ->options(
                            collect(\App\Domain\Fraud\Enums\AnomalyStatus::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                                ->all()
                        ),
                    Tables\Filters\SelectFilter::make('anomaly_type')
                        ->label('Type')
                        ->options(
                            collect(\App\Domain\Fraud\Enums\AnomalyType::cases())
                                ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                                ->all()
                        ),
                    Tables\Filters\SelectFilter::make('severity')
                        ->options(
                            [
                                'critical' => 'Critical',
                                'high'     => 'High',
                                'medium'   => 'Medium',
                                'low'      => 'Low',
                            ]
                        ),
                ]
            )
            ->actions(
                [
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('assign')
                        ->label('Assign')
                        ->icon('heroicon-o-user-plus')
                        ->color('info')
                        ->visible(fn (AnomalyDetection $record): bool => ! $record->status->isTerminal()
                            && $record->triage_status === 'detected'
                            && (auth()->user()?->can('triage-anomalies') ?? false))
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Assign to Analyst')
                                ->options(
                                    \App\Models\User::role('fraud-analyst')
                                        ->get()
                                        ->pluck('name', 'id')
                                )
                                ->required(),
                        ])
                        ->action(function (AnomalyDetection $record, array $data): void {
                            $record->update([
                                'triage_status' => 'under_review',
                                'assigned_to'   => $data['assigned_to'],
                            ]);
                        }),
                    Tables\Actions\Action::make('escalate')
                        ->label('Escalate')
                        ->icon('heroicon-o-arrow-up-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (AnomalyDetection $record): bool => $record->triage_status === 'under_review'
                            && (auth()->user()?->can('triage-anomalies') ?? false))
                        ->form([
                            Forms\Components\Textarea::make('escalation_note')
                                ->label('Escalation reason')
                                ->required(),
                        ])
                        ->action(function (AnomalyDetection $record, array $data): void {
                            $record->update(['triage_status' => 'escalated']);
                            \App\Domain\Support\Models\SupportCase::create([
                                'subject'               => 'Escalated anomaly: ' . $record->id,
                                'description'           => $data['escalation_note'],
                                'status'                => 'open',
                                'priority'              => 'urgent',
                                'reported_by_user_uuid' => auth()->user()->uuid ?? null,
                                'reported_by'           => auth()->id(),
                            ]);
                        }),
                    Tables\Actions\Action::make('resolve')
                        ->label('Resolve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (AnomalyDetection $record): bool => in_array($record->triage_status, ['under_review', 'escalated'])
                            && ! $record->status->isTerminal()
                            && (auth()->user()?->can('triage-anomalies') ?? false))
                        ->form([
                            Forms\Components\Select::make('resolution_type')
                                ->options([
                                    'fraud'          => 'Confirmed Fraud',
                                    'false_positive' => 'False Positive',
                                    'low_risk'       => 'Low Risk',
                                ])
                                ->required(),
                            Forms\Components\Textarea::make('resolution_notes')
                                ->label('Resolution Notes')
                                ->required(),
                        ])
                        ->action(function (AnomalyDetection $record, array $data): void {
                            $record->update([
                                'triage_status'    => $data['resolution_type'] === 'false_positive' ? 'false_positive' : 'resolved',
                                'resolution_type'  => $data['resolution_type'],
                                'resolution_notes' => $data['resolution_notes'],
                                'resolved_by'      => auth()->id(),
                                'resolved_at'      => now(),
                                'status'           => $data['resolution_type'] === 'false_positive'
                                    ? \App\Domain\Fraud\Enums\AnomalyStatus::FalsePositive
                                    : \App\Domain\Fraud\Enums\AnomalyStatus::Resolved,
                                'feedback_outcome' => $data['resolution_type'],
                                'reviewed_at'      => now(),
                                'reviewed_by'      => auth()->id(),
                            ]);
                        }),
                    Tables\Actions\Action::make('mark_false_positive')
                        ->label('False Positive')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->visible(fn (AnomalyDetection $record): bool => ! $record->status->isTerminal()
                            && (auth()->user()?->can('triage-anomalies') ?? false))
                        ->action(
                            fn (AnomalyDetection $record) => $record->update([
                                'status'           => \App\Domain\Fraud\Enums\AnomalyStatus::FalsePositive,
                                'triage_status'    => 'false_positive',
                                'feedback_outcome' => 'false_positive',
                                'resolved_at'      => now(),
                                'resolved_by'      => auth()->id(),
                                'reviewed_at'      => now(),
                            ])
                        ),
                ]
            )
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnomalyDetections::route('/'),
            'view'  => Pages\ViewAnomalyDetection::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
