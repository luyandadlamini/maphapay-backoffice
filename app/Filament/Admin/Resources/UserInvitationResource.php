<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\User\Models\UserInvitation;
use App\Domain\User\Services\UserInvitationService;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\UserInvitationResource\Pages;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class UserInvitationResource extends Resource
{
    use RespectsModuleVisibility;
    use HasBackofficeWorkspace;

    protected static ?string $model = UserInvitation::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Invitations';

    protected static ?string $modelLabel = 'Invitation';

    protected static string $backofficeWorkspace = 'platform_administration';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Invite a New User')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique('user_invitations', 'email', ignoreRecord: true)
                            ->unique('users', 'email')
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->options([
                                'private'     => 'User (Private)',
                                'admin'       => 'Admin',
                                'super-admin' => 'Super Admin',
                            ])
                            ->default('private')
                            ->required(),
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin', 'super-admin' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('inviter.name')
                    ->label('Invited By'),
                Tables\Columns\TextColumn::make('status')
                    ->getStateUsing(function (UserInvitation $record): string {
                        if ($record->isAccepted()) {
                            return 'Accepted';
                        }
                        if ($record->isExpired()) {
                            return 'Expired';
                        }

                        return 'Pending';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Accepted' => 'success',
                        'Expired'  => 'danger',
                        default    => 'info',
                    }),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'private'     => 'User',
                        'admin'       => 'Admin',
                        'super-admin' => 'Super Admin',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('resend')
                    ->label('Resend')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->visible(fn (UserInvitation $record): bool => $record->isPending() || $record->isExpired())
                    ->action(function (UserInvitation $record, array $data): void {
                        try {
                            static::authorizeWorkspace();

                            /** @var \App\Models\User $inviter */
                            $inviter = auth()->user();
                            $oldValues = [
                                'token' => $record->token,
                                'expires_at' => $record->expires_at->toIso8601String(),
                            ];
                            app(UserInvitationService::class)->resend($record->id, $inviter);

                            $record->refresh();

                            static::adminActionGovernance()->auditDirectAction(
                                workspace: static::getBackofficeWorkspace(),
                                action: 'backoffice.user_invitations.resent',
                                reason: (string) $data['reason'],
                                auditable: $record,
                                oldValues: $oldValues,
                                newValues: [
                                    'token' => $record->token,
                                    'expires_at' => $record->expires_at->toIso8601String(),
                                ],
                                metadata: [
                                    'email' => $record->email,
                                    'role' => $record->role,
                                    'actor_email' => $inviter->email,
                                ],
                                tags: 'backoffice,platform,user-invitations'
                            );

                            Notification::make()->title('Invitation resent')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->visible(fn (UserInvitation $record): bool => $record->isPending())
                    ->action(function (UserInvitation $record, array $data): void {
                        try {
                            /** @var \App\Models\User|null $actor */
                            $actor = auth()->user();
                            static::authorizeWorkspace();

                            $oldValues = [
                                'expires_at' => $record->expires_at->toIso8601String(),
                            ];
                            app(UserInvitationService::class)->revoke($record->id);

                            $record->refresh();

                            static::adminActionGovernance()->auditDirectAction(
                                workspace: static::getBackofficeWorkspace(),
                                action: 'backoffice.user_invitations.revoked',
                                reason: (string) $data['reason'],
                                auditable: $record,
                                oldValues: $oldValues,
                                newValues: [
                                    'expires_at' => $record->expires_at->toIso8601String(),
                                ],
                                metadata: [
                                    'email' => $record->email,
                                    'role' => $record->role,
                                    'actor_email' => $actor instanceof \App\Models\User ? $actor->email : 'system',
                                ],
                                tags: 'backoffice,platform,user-invitations'
                            );

                            Notification::make()->title('Invitation revoked')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('copyLink')
                    ->label('Copy Link')
                    ->icon('heroicon-o-clipboard')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->visible(fn (UserInvitation $record): bool => $record->isPending())
                    ->action(function (UserInvitation $record, array $data): void {
                        /** @var \App\Models\User|null $actor */
                        $actor = auth()->user();
                        static::authorizeWorkspace();

                        $url = config('app.url') . '/invitation/accept?token=' . $record->token;

                        static::adminActionGovernance()->auditDirectAction(
                            workspace: static::getBackofficeWorkspace(),
                            action: 'backoffice.user_invitations.link_copied',
                            reason: (string) $data['reason'],
                            auditable: $record,
                            metadata: [
                                'email' => $record->email,
                                'role' => $record->role,
                                'invitation_url' => $url,
                                'actor_email' => $actor instanceof \App\Models\User ? $actor->email : 'system',
                            ],
                            tags: 'backoffice,platform,user-invitations'
                        );

                        Notification::make()
                            ->title('Invitation link')
                            ->body($url)
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUserInvitations::route('/'),
            'create' => Pages\CreateUserInvitation::route('/create'),
        ];
    }

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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function adminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    public static function authorizeWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());
    }
}
