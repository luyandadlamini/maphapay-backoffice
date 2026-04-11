<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\User\Values\UserRoles;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Models\User;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class BroadcastNotificationPage extends Page implements HasForms, HasActions
{
    use HasBackofficeWorkspace;
    use InteractsWithForms;
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Broadcast Notifications';

    protected static string $view = 'filament.admin.pages.broadcast-notification-page';

    protected static string $backofficeWorkspace = 'platform_administration';

    public string $channel = 'database';

    public string $audience = 'all';

    public ?int $userId = null;

    public ?string $role = null;

    public string $subject = '';

    public string $body = '';

    public static function canAccess(): bool
    {
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public function getBroadcastFormSchema(): array
    {
        return [
            Section::make('Notification Settings')
                ->schema([
                    Select::make('channel')
                        ->label('Channel')
                        ->options([
                            'database' => 'In-App',
                            'mail'     => 'Email',
                            'sms'      => 'SMS',
                            'push'     => 'Push Notification',
                        ])
                        ->default('database')
                        ->required(),

                    Select::make('audience')
                        ->label('Audience')
                        ->options([
                            'all'  => 'All Active Users',
                            'role' => 'Role Group',
                            'user' => 'Single User',
                        ])
                        ->default('all')
                        ->required()
                        ->reactive(),

                    Select::make('userId')
                        ->label('Select User')
                        ->options(function () {
                            return User::query()
                                ->pluck('email', 'id');
                        })
                        ->visible(fn (callable $get) => $get('audience') === 'user'),

                    Select::make('role')
                        ->label('Select Role')
                        ->options([
                            UserRoles::OPERATIONS_L2->value      => 'Operations L2',
                            UserRoles::FINANCE_LEAD->value       => 'Finance Lead',
                            UserRoles::COMPLIANCE_MANAGER->value => 'Compliance Manager',
                            UserRoles::SUPPORT_L1->value         => 'Support L1',
                        ])
                        ->visible(fn (callable $get) => $get('audience') === 'role'),
                ])
                ->columns(2),

            Section::make('Message Content')
                ->schema([
                    TextInput::make('subject')
                        ->label('Subject')
                        ->required()
                        ->maxLength(255),

                    Textarea::make('body')
                        ->label('Message')
                        ->required()
                        ->rows(5)
                        ->maxLength(5000),
                ]),
        ];
    }

    protected function getForms(): array
    {
        return [
            'form' => $this->makeForm()
                ->schema($this->getBroadcastFormSchema()),
        ];
    }

    protected function getActions(): array
    {
        return [
            Action::make('send')
                ->label('Send Notification')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Send Broadcast Notification')
                ->modalDescription('This will send a notification to the selected recipients.')
                ->form([
                    ...$this->getBroadcastFormSchema(),
                    Textarea::make('reason')
                        ->label('Reason for broadcast')
                        ->required()
                        ->minLength(10)
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $this->dispatchBroadcast($data);
                }),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function dispatchBroadcast(array $data): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());

        Validator::make($data, [
            'channel' => ['required', 'string'],
            'audience' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'min:10'],
            'userId' => ['nullable', 'integer'],
            'role' => ['nullable', 'string'],
        ])->validate();

        $recipients = $this->getRecipients($data['audience'], $data['userId'] ?? null, $data['role'] ?? null);

        if ($recipients->isEmpty()) {
            Notification::make()
                ->title('No recipients found')
                ->warning()
                ->send();

            return;
        }

        $count = 0;
        foreach ($recipients as $recipient) {
            Notification::make()
                ->title($data['subject'])
                ->body($data['body'])
                ->sendToDatabase($recipient);

            $count++;
        }

        app(AdminActionGovernance::class)->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.broadcast_notifications.sent',
            reason: (string) $data['reason'],
            metadata: [
                'channel' => $data['channel'],
                'audience' => $data['audience'],
                'user_id' => $data['userId'] ?? null,
                'role' => $data['role'] ?? null,
                'recipient_count' => $count,
                'subject' => $data['subject'],
                'actor_email' => auth()->user()->email ?? 'system',
            ],
            tags: 'backoffice,platform,broadcast-notifications'
        );

        Notification::make()
            ->title('Notification sent')
            ->body("Successfully sent to {$count} recipient(s)")
            ->success()
            ->send();
    }

    private function getRecipients(string $audience, ?int $userId, ?string $role): Collection
    {
        return match ($audience) {
            'user'  => $userId ? User::where('id', $userId)->get() : collect(),
            'role'  => $role ? User::role($role)->get() : collect(),
            default => User::all(),
        };
    }
}
