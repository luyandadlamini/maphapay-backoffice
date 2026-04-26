<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\SettingsFieldFactory;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fee parameters from {@see SettingsService} `fees` group only (REQ-FEE-001, REQ-SEC-001).
 */
class RevenuePricingPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Pricing & fees';

    protected static ?string $navigationGroup = 'Revenue & Performance';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Pricing & fees';

    protected static string $view = 'filament.admin.pages.revenue-pricing-page';

    /**
     * @var array<string, mixed>|null
     */
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
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $access = app(BackofficeWorkspaceAccess::class);

        return $access->canAccess('finance', $user)
            || $access->canAccess('platform_administration', $user);
    }

    public function mount(): void
    {
        $this->fillFeesForm();
    }

    /**
     * @return array{label: string, settings: array<string, array<string, mixed>>}
     */
    protected function feesGroupConfig(): array
    {
        $config = $this->getSettingsService()->getConfig();

        return $config['fees'];
    }

    /**
     * @return list<string>
     */
    protected function feeSettingKeys(): array
    {
        return array_keys($this->feesGroupConfig()['settings']);
    }

    protected function fillFeesForm(): void
    {
        $keys = $this->feeSettingKeys();
        $ttl = (int) config('maphapay.revenue_admin_read_cache_ttl_seconds', 120);

        $settings = Cache::tags(['revenue', 'pricing'])->remember(
            $this->revenuePricingFeeSettingsCacheKey(),
            max(5, $ttl),
            fn (): array => Setting::query()
                ->whereIn('key', $keys)
                ->pluck('value', 'key')
                ->toArray()
        );

        $subset = array_intersect_key($settings, array_flip($keys));
        $this->getForm('form')?->fill($subset);
    }

    private function revenuePricingFeeSettingsCacheKey(): string
    {
        $suffix = 'landlord';

        try {
            if (function_exists('tenancy') && tenancy()->initialized) {
                $suffix = (string) tenant('id');
            }
        } catch (Throwable) {
            // Landlord / unknown context: single shared key.
        }

        return 'revenue_pricing:fee_setting_values:' . $suffix;
    }

    public function form(Form $form): Form
    {
        $fees = $this->feesGroupConfig();
        $fields = [];

        foreach ($fees['settings'] as $key => $config) {
            $fields[] = SettingsFieldFactory::make($key, $config);
        }

        return $form
            ->schema(
                [
                    Forms\Components\Section::make($fees['label'])
                        ->description(
                            __(
                                'Same keys and validation as Platform Settings → Fee Management. A governance reason is required to save (same as Platform Settings).'
                            )
                        )
                        ->schema($fields)
                        ->columns(2),
                ]
            )
            ->statePath('data');
    }

    public function saveFees(): void
    {
        try {
            $this->validate([
                'governanceReason' => ['required', 'string', 'min:10'],
            ]);

            $data = array_replace($this->getForm('form')?->getState() ?? [], $this->data ?? []);
            $feeKeySet = array_flip($this->feeSettingKeys());

            $changedKeys = [];
            $oldValues = [];
            $newValues = [];

            foreach ($data as $key => $value) {
                if (! isset($feeKeySet[$key])) {
                    continue;
                }

                $config = $this->getSettingsService()->getSettingConfig($key);

                if (empty($config)) {
                    continue;
                }

                $validation = $this->getSettingsService()->validateSetting($key, $value);

                if (! $validation['valid']) {
                    Notification::make()
                        ->title(__('Validation error'))
                        ->body(
                            __('Invalid value for :label: :errors', [
                                'label'  => $config['label'],
                                'errors' => implode(', ', $validation['errors']),
                            ])
                        )
                        ->danger()
                        ->send();

                    throw new Halt();
                }

                $existing = Setting::query()->where('key', $key)->first();

                if ($existing?->value !== $value) {
                    $changedKeys[] = $key;
                    $oldValues[$key] = $existing?->value;
                    $newValues[$key] = $value;
                }

                $this->getSettingsService()->updateSetting($key, $value, auth()->user()->email ?? 'system');
            }

            Cache::forget($this->revenuePricingFeeSettingsCacheKey());
            Cache::tags(['revenue', 'pricing'])->flush();

            $this->getAdminActionGovernance()->auditDirectAction(
                workspace: $this->resolveGovernanceWorkspace(),
                action: 'backoffice.revenue_pricing.fees_saved',
                reason: (string) $this->governanceReason,
                oldValues: $oldValues,
                newValues: $newValues,
                metadata: [
                    'changed_keys' => $changedKeys,
                    'actor_email'  => auth()->user()->email ?? 'system',
                ],
                tags: 'backoffice,finance,revenue_pricing,fees'
            );

            Notification::make()
                ->title(__('Fee settings saved'))
                ->success()
                ->send();

            Log::info(
                'Revenue pricing fees updated',
                [
                    'user'         => auth()->user()->email ?? 'system',
                    'changed_keys' => $changedKeys,
                ]
            );
        } catch (Halt $exception) {
            return;
        }

        $this->governanceReason = null;
    }

    private function resolveGovernanceWorkspace(): string
    {
        $user = auth()->user();
        $access = app(BackofficeWorkspaceAccess::class);

        if ($user !== null && $access->canAccess('finance', $user)) {
            return 'finance';
        }

        return 'platform_administration';
    }
}
