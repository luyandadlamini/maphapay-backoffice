<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
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

class BroadcastNotificationPage extends Page implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Broadcast Notifications';

    protected static string $view = 'filament.admin.pages.broadcast-notification-page';

    public string $channel = 'database';

    public string $audience = 'all';

    public ?int $userId = null;

    public ?string $role = null;

    public string $subject = '';

    public string $body = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('operations-l2'));
    }

    protected function getForms(): array
    {
        return [
            'form' => $this->makeForm()
                ->schema([
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
                                    'operations-l2'      => 'Operations L2',
                                    'finance-lead'       => 'Finance Lead',
                                    'compliance-manager' => 'Compliance Manager',
                                    'support-l1'         => 'Support L1',
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
                ]),
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
                ->form($this->getForms()['form'])
                ->action(function (array $data): void {
                    $this->sendNotification($data);
                }),
        ];
    }

    private function sendNotification(array $data): void
    {
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
