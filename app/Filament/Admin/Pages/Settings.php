<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Backoffice\AdminActionGovernance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Settings extends Page
{
    use HasBackofficeWorkspace;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.admin.pages.settings';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 100;

    protected static ?string $title = 'Platform Settings';

    protected static string $backofficeWorkspace = 'platform_administration';

    public ?array $data = [];

    public ?string $governanceReason = null;

    protected ?SettingsService $settingsService = null;

    protected ?AdminActionGovernance $adminActionGovernance = null;

    public function boot(): void
    {
        $this->settingsService = app(SettingsService::class);
        $this->adminActionGovernance = app(AdminActionGovernance::class);
    }

    protected function getSettingsService(): SettingsService
    {
        return $this->settingsService ??= app(SettingsService::class);
    }

    protected function getAdminActionGovernance(): AdminActionGovernance
    {
        return $this->adminActionGovernance ??= app(AdminActionGovernance::class);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();
        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        $schema = [];

        foreach ($this->getSettingsService()->getConfig() as $group => $groupConfig) {
            $fields = [];

            foreach ($groupConfig['settings'] as $key => $config) {
                $field = match ($config['type']) {
                    'boolean' => Forms\Components\Toggle::make($key)
                        ->label($config['label'])
                        ->helperText($config['description']),
                    'integer' => Forms\Components\TextInput::make($key)
                        ->label($config['label'])
                        ->numeric()
                        ->helperText($config['description']),
                    'float' => Forms\Components\TextInput::make($key)
                        ->label($config['label'])
                        ->numeric()
                        ->step(0.01)
                        ->helperText($config['description']),
                    'string' => Forms\Components\TextInput::make($key)
                        ->label($config['label'])
                        ->helperText($config['description']),
                    'array' => Forms\Components\TagsInput::make($key)
                        ->label($config['label'])
                        ->helperText($config['description']),
                    'json' => Forms\Components\KeyValue::make($key)
                        ->label($config['label'])
                        ->helperText($config['description']),
                    default => Forms\Components\TextInput::make($key)
                        ->label($config['label'])
                        ->helperText($config['description']),
                };

                $fields[] = $field;
            }

            $schema[] = Forms\Components\Section::make($groupConfig['label'])
                ->description("Configure {$groupConfig['label']} for the platform")
                ->schema($fields)
                ->collapsible()
                ->persistCollapsed();
        }

        return $form
            ->schema($schema)
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $this->validate([
                'governanceReason' => ['required', 'string', 'min:10'],
            ]);

            $data = array_replace($this->form->getState(), $this->data ?? []);
            $changedKeys = [];
            $oldValues = [];
            $newValues = [];

            foreach ($data as $key => $value) {
                $config = $this->getSettingsService()->getSettingConfig($key);

                if (empty($config)) {
                    continue;
                }

                $validation = $this->getSettingsService()->validateSetting($key, $value);

                if (! $validation['valid']) {
                    Notification::make()
                        ->title('Validation Error')
                        ->body("Invalid value for {$config['label']}: " . implode(', ', $validation['errors']))
                        ->danger()
                        ->send();

                    throw new Halt();
                }

                $existing = Setting::where('key', $key)->first();

                if ($existing?->value !== $value) {
                    $changedKeys[] = $key;
                    $oldValues[$key] = $existing?->value;
                    $newValues[$key] = $value;
                }

                $this->getSettingsService()->updateSetting($key, $value, auth()->user()->email ?? 'system');
            }

            Cache::flush();

            $this->getAdminActionGovernance()->auditDirectAction(
                workspace: static::getBackofficeWorkspace(),
                action: 'backoffice.settings.saved',
                reason: (string) $this->governanceReason,
                oldValues: $oldValues,
                newValues: $newValues,
                metadata: [
                    'changed_keys' => $changedKeys,
                    'actor_email'  => auth()->user()->email ?? 'system',
                ],
                tags: 'backoffice,platform,settings'
            );

            Notification::make()
                ->title('Settings saved successfully')
                ->success()
                ->send();

            Log::info(
                'Platform settings updated',
                [
                    'user'           => auth()->user()->email ?? 'system',
                    'settings_count' => count($data),
                ]
            );
        } catch (Halt $exception) {
            return;
        }

        $this->governanceReason = null;
    }

    public function requestResetToDefaults(string $reason): void
    {
        $this->getAdminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.settings.reset_to_defaults',
            reason: $reason,
            targetType: 'settings_group',
            targetIdentifier: 'all',
            payload: [
                'scope' => 'all_settings',
            ],
            metadata: [
                'actor_email' => auth()->user()->email ?? 'system',
            ],
        );

        Notification::make()
            ->title('Reset request submitted')
            ->body('This settings reset now requires approval before execution.')
            ->warning()
            ->send();
    }

    public function exportSettings(string $reason): void
    {
        $settings = $this->getSettingsService()->exportSettings();

        $filename = 'settings-export-' . now()->format('Y-m-d-His') . '.json';

        Notification::make()
            ->title('Settings exported')
            ->body('Settings have been exported successfully.')
            ->success()
            ->send();

        $this->getAdminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.settings.exported',
            reason: $reason,
            metadata: [
                'filename'       => $filename,
                'settings_count' => count($settings),
                'actor_email'    => auth()->user()->email ?? 'system',
            ],
            tags: 'backoffice,platform,settings,export'
        );

        // In a real implementation, this would trigger a download
        Log::info(
            'Settings exported',
            [
                'user'           => auth()->user()->email ?? 'system',
                'filename'       => $filename,
                'settings_count' => count($settings),
            ]
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('requestReset')
                ->label('Reset to Defaults')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Reset Settings to Defaults')
                ->modalDescription('Are you sure you want to reset all settings to their default values? This action cannot be undone.')
                ->modalSubmitActionLabel('Yes, reset all settings')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Approval request reason')
                        ->required()
                        ->minLength(10),
                ])
                ->action(fn (array $data) => $this->requestResetToDefaults($data['reason'])),

            \Filament\Actions\Action::make('export')
                ->label('Export Settings')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Export reason')
                        ->required()
                        ->minLength(10),
                ])
                ->action(fn (array $data) => $this->exportSettings($data['reason'])),
        ];
    }
}
