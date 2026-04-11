<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Webhook\Models\Webhook;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\WebhookResource\Pages;
use App\Filament\Admin\Resources\WebhookResource\RelationManagers;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WebhookResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;
    use HasBackofficeWorkspace;

    protected static ?string $model = Webhook::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-top-right-on-square';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 3;

    protected static string $backofficeWorkspace = 'platform_administration';

    public static function canViewAny(): bool
    {
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(
                [
                    Forms\Components\Section::make('Webhook Details')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('description')
                                    ->maxLength(65535)
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('url')
                                    ->required()
                                    ->url()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ]
                        ),

                    Forms\Components\Section::make('Configuration')
                        ->schema(
                            [
                                Forms\Components\CheckboxList::make('events')
                                    ->options(Webhook::EVENTS)
                                    ->columns(2)
                                    ->required()
                                    ->helperText('Select the events that will trigger this webhook'),
                                Forms\Components\TextInput::make('secret')
                                    ->password()
                                    ->maxLength(255)
                                    ->helperText('Optional secret for webhook signature verification'),
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true),
                            ]
                        ),

                    Forms\Components\Section::make('Advanced Settings')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('retry_attempts')
                                    ->numeric()
                                    ->default(3)
                                    ->minValue(0)
                                    ->maxValue(10),
                                Forms\Components\TextInput::make('timeout_seconds')
                                    ->numeric()
                                    ->default(30)
                                    ->minValue(5)
                                    ->maxValue(300)
                                    ->suffix('seconds'),
                                Forms\Components\KeyValue::make('headers')
                                    ->label('Custom Headers')
                                    ->keyLabel('Header Name')
                                    ->valueLabel('Header Value')
                                    ->addButtonLabel('Add Header')
                                    ->helperText('Optional custom headers to include in webhook requests'),
                            ]
                        )
                        ->collapsible(),
                ]
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('name')
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('url')
                        ->searchable()
                        ->limit(50)
                        ->tooltip(fn ($record) => $record->url),
                    Tables\Columns\TagsColumn::make('events')
                        ->limit(3),
                    Tables\Columns\IconColumn::make('is_active')
                        ->boolean()
                        ->label('Active'),
                    Tables\Columns\TextColumn::make('consecutive_failures')
                        ->numeric()
                        ->label('Failures')
                        ->color(fn ($state) => $state > 0 ? ($state >= 5 ? 'danger' : 'warning') : 'gray'),
                    Tables\Columns\TextColumn::make('last_triggered_at')
                        ->dateTime('M j, Y g:i A')
                        ->sortable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('last_success_at')
                        ->dateTime('M j, Y g:i A')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('created_at')
                        ->dateTime('M j, Y g:i A')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ]
            )
            ->filters(
                [
                    Tables\Filters\SelectFilter::make('is_active')
                        ->options(
                            [
                                '1' => 'Active',
                                '0' => 'Inactive',
                            ]
                        )
                        ->label('Status'),
                    Tables\Filters\Filter::make('has_failures')
                        ->query(fn (Builder $query): Builder => $query->where('consecutive_failures', '>', 0))
                        ->label('Has Failures'),
                ]
            )
            ->actions(
                [
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('test')
                        ->label('Test')
                        ->icon('heroicon-o-play')
                        ->color('gray')
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->required()
                                ->minLength(10),
                        ])
                        ->action(
                            function (Webhook $record, array $data): void {
                                static::authorizeWorkspace();
                                /** @var \App\Models\User|null $actor */
                                $actor = auth()->user();

                                $record->deliveries()->create(
                                    [
                                        'event_type' => 'test.webhook',
                                        'payload'    => [
                                            'event'     => 'test.webhook',
                                            'timestamp' => now()->toIso8601String(),
                                            'message'   => 'This is a test webhook delivery',
                                        ],
                                        'status' => 'pending',
                                    ]
                                );

                            static::adminActionGovernance()->auditDirectAction(
                                workspace: static::getBackofficeWorkspace(),
                                action: 'backoffice.webhooks.tested',
                                reason: (string) $data['reason'],
                                auditable: $record,
                                metadata: [
                                        'webhook' => $record->name,
                                        'url' => $record->url,
                                        'actor_email' => $actor instanceof \App\Models\User ? $actor->email : 'system',
                                ],
                                tags: 'backoffice,platform,webhooks'
                            );

                                Notification::make()
                                    ->title('Test webhook created')
                                    ->body('A test delivery has been queued for processing.')
                                    ->success()
                                    ->send();
                            }
                        ),
                    Tables\Actions\Action::make('reset_failures')
                        ->label('Reset Failures')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn ($record) => $record->consecutive_failures > 0)
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->required()
                                ->minLength(10),
                        ])
                        ->requiresConfirmation()
                        ->action(function (Webhook $record, array $data): void {
                            static::authorizeWorkspace();
                            /** @var \App\Models\User|null $actor */
                            $actor = auth()->user();

                            $oldValues = [
                                'consecutive_failures' => $record->consecutive_failures,
                                'is_active' => $record->is_active,
                            ];

                            $record->update(
                                [
                                    'consecutive_failures' => 0,
                                    'is_active' => true,
                                ]
                            );

                            static::adminActionGovernance()->auditDirectAction(
                                workspace: static::getBackofficeWorkspace(),
                                action: 'backoffice.webhooks.failures_reset',
                                reason: (string) $data['reason'],
                                auditable: $record,
                                oldValues: $oldValues,
                                newValues: [
                                    'consecutive_failures' => $record->fresh()?->consecutive_failures,
                                    'is_active' => $record->fresh()?->is_active,
                                ],
                                metadata: [
                                    'webhook' => $record->name,
                                    'actor_email' => $actor instanceof \App\Models\User ? $actor->email : 'system',
                                ],
                                tags: 'backoffice,platform,webhooks'
                            );
                        }),
                    Tables\Actions\Action::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Webhook $record): bool => ! $record->is_active)
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->required()
                                ->minLength(10),
                        ])
                        ->requiresConfirmation()
                        ->action(function (Webhook $record, array $data): void {
                            static::submitStateApprovalRequest(
                                record: $record,
                                requestedState: 'active',
                                action: 'backoffice.webhooks.activate',
                                reason: (string) $data['reason'],
                            );
                        }),
                    Tables\Actions\Action::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Webhook $record): bool => $record->is_active)
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->required()
                                ->minLength(10),
                        ])
                        ->requiresConfirmation()
                        ->action(function (Webhook $record, array $data): void {
                            static::submitStateApprovalRequest(
                                record: $record,
                                requestedState: 'inactive',
                                action: 'backoffice.webhooks.deactivate',
                                reason: (string) $data['reason'],
                            );
                        }),
                    Tables\Actions\Action::make('delete')
                        ->label('Delete')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->required()
                                ->minLength(10),
                        ])
                        ->requiresConfirmation()
                        ->action(function (Webhook $record, array $data): void {
                            static::submitDeletionApprovalRequest(
                                record: $record,
                                reason: (string) $data['reason'],
                            );
                        }),
                ]
            )
            ->bulkActions(
                [
                    Tables\Actions\BulkActionGroup::make(
                        [
                            Tables\Actions\BulkAction::make('delete')
                                ->label('Delete')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->required()
                                        ->minLength(10),
                                ])
                                ->requiresConfirmation()
                                ->action(function ($records, array $data): void {
                                    static::authorizeWorkspace();

                                    static::adminActionGovernance()->submitApprovalRequest(
                                        workspace: static::getBackofficeWorkspace(),
                                        action: 'backoffice.webhooks.delete.bulk',
                                        reason: (string) $data['reason'],
                                        payload: [
                                            'webhook_ids' => $records->map(fn (Webhook $record) => (string) $record->getKey())->values()->all(),
                                        ],
                                        metadata: [
                                            'count' => $records->count(),
                                        ],
                                    );
                                }),
                            Tables\Actions\BulkAction::make('activate')
                                ->label('Activate')
                                ->icon('heroicon-o-check-circle')
                                ->color('success')
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->required()
                                        ->minLength(10),
                                ])
                                ->action(function ($records, array $data): void {
                                    static::authorizeWorkspace();

                                    static::adminActionGovernance()->submitApprovalRequest(
                                        workspace: static::getBackofficeWorkspace(),
                                        action: 'backoffice.webhooks.activate.bulk',
                                        reason: (string) $data['reason'],
                                        payload: [
                                            'webhook_ids' => $records->map(fn (Webhook $record) => (string) $record->getKey())->values()->all(),
                                            'requested_state' => 'active',
                                        ],
                                        metadata: [
                                            'count' => $records->count(),
                                        ],
                                    );
                                })
                                ->requiresConfirmation(),
                            Tables\Actions\BulkAction::make('deactivate')
                                ->label('Deactivate')
                                ->icon('heroicon-o-x-circle')
                                ->color('danger')
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->required()
                                        ->minLength(10),
                                ])
                                ->action(function ($records, array $data): void {
                                    static::authorizeWorkspace();

                                    static::adminActionGovernance()->submitApprovalRequest(
                                        workspace: static::getBackofficeWorkspace(),
                                        action: 'backoffice.webhooks.deactivate.bulk',
                                        reason: (string) $data['reason'],
                                        payload: [
                                            'webhook_ids' => $records->map(fn (Webhook $record) => (string) $record->getKey())->values()->all(),
                                            'requested_state' => 'inactive',
                                        ],
                                        metadata: [
                                            'count' => $records->count(),
                                        ],
                                    );
                                })
                                ->requiresConfirmation(),
                        ]
                    ),
                ]
            );
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DeliveriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWebhooks::route('/'),
            'create' => Pages\CreateWebhook::route('/create'),
            'edit'   => Pages\EditWebhook::route('/{record}/edit'),
            'view'   => Pages\ViewWebhook::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(
                ['deliveries', 'deliveries as failed_deliveries_count' => function ($query) {
                    $query->where('status', 'failed');
                }]
            );
    }

    protected static function adminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    public static function authorizeWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());
    }

    protected static function submitStateApprovalRequest(Webhook $record, string $requestedState, string $action, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: $action,
            reason: $reason,
            targetType: Webhook::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'webhook_uuid' => (string) $record->getKey(),
                'requested_state' => $requestedState,
            ],
            metadata: [
                'webhook' => $record->name,
                'url' => $record->url,
            ],
        );

        Notification::make()
            ->title('Webhook change submitted for approval')
            ->success()
            ->send();
    }

    protected static function submitDeletionApprovalRequest(Webhook $record, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.webhooks.delete',
            reason: $reason,
            targetType: Webhook::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'webhook_uuid' => (string) $record->getKey(),
            ],
            metadata: [
                'webhook' => $record->name,
                'url' => $record->url,
            ],
        );

        Notification::make()
            ->title('Webhook deletion submitted for approval')
            ->success()
            ->send();
    }
}
