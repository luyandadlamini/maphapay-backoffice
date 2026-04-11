<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Product\Services\SubProductService;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SubProducts extends Page implements HasForms, HasActions
{
    use HasBackofficeWorkspace;
    use InteractsWithForms;
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string $view = 'filament.admin.pages.sub-products';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Sub-Product Configuration';

    protected static string $backofficeWorkspace = 'platform_administration';

    public ?array $data = [];

    public ?string $governanceReason = null;

    protected SubProductService $subProductService;

    public function boot(): void
    {
        $this->subProductService = app(SubProductService::class);
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public static function canAccess(): bool
    {
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

    protected function fillForm(): void
    {
        $formData = [];
        $subProducts = $this->subProductService->getAllSubProducts();

        foreach ($subProducts as $key => $config) {
            $formData["{$key}_enabled"] = $config['is_enabled'] ?? false;

            foreach ($config['features'] ?? [] as $feature => $default) {
                $formData["{$key}_{$feature}"] = $this->subProductService->isFeatureEnabled($key, $feature);
            }
        }

        $this->form->fill($formData);
    }

    public function form(Form $form): Form
    {
        $schema = [];
        $subProducts = config('sub_products', []);

        foreach ($subProducts as $key => $config) {
            $featureFields = [];

            foreach ($config['features'] ?? [] as $feature => $default) {
                $featureFields[] = Forms\Components\Toggle::make("{$key}_{$feature}")
                    ->label((string) str($feature)->replace('_', ' ')->title())
                    ->helperText("Enable {$feature} functionality")
                    ->disabled(fn (Forms\Get $get) => ! $get("{$key}_enabled"))
                    ->columnSpan(1);
            }

            $schema[] = Forms\Components\Section::make($config['name'])
                ->description($config['description'])
                ->icon($config['icon'] ?? 'heroicon-o-squares-2x2')
                ->schema(
                    [
                        Forms\Components\Toggle::make("{$key}_enabled")
                            ->label('Enable ' . $config['name'])
                            ->helperText("Enable or disable the entire {$config['name']} sub-product")
                            ->reactive()
                            ->afterStateUpdated(
                                function ($state, Forms\Set $set) use ($key, $config) {
                                    if (! $state) {
                                        // Disable all features when sub-product is disabled
                                        foreach ($config['features'] as $feature => $default) {
                                            $set("{$key}_{$feature}", false);
                                        }
                                    }
                                }
                            )
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)
                            ->schema($featureFields)
                            ->visible(fn (Forms\Get $get) => $get("{$key}_enabled")),

                        Forms\Components\Placeholder::make("{$key}_licenses")
                            ->label('Required Licenses')
                            ->content(implode(', ', $config['licenses']))
                            ->visible(fn (Forms\Get $get) => $get("{$key}_enabled")),

                        Forms\Components\Placeholder::make("{$key}_status")
                            ->label('Status')
                            ->content(
                                fn (Forms\Get $get) => $get("{$key}_enabled") ?
                                '<span class="text-success-600">Active</span>' :
                                '<span class="text-danger-600">Inactive</span>'
                            )
                            ->extraAttributes(['class' => 'font-semibold'])
                            ->columnSpanFull(),
                    ]
                )
                ->collapsible()
                ->persistCollapsed();
        }

        $schema[] = Forms\Components\Section::make('Governance')
            ->description('Capture the operating evidence for this configuration request.')
            ->schema([
                Forms\Components\Textarea::make('governance_reason')
                    ->label('Reason for configuration request')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Forms\Components\Textarea $component): void {
                        $component->state($this->governanceReason);
                    })
                    ->afterStateUpdated(function (?string $state): void {
                        $this->governanceReason = $state;
                    }),
            ]);

        return $form
            ->schema($schema)
            ->statePath('data');
    }

    public function save(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());

        $data = $this->form->getState();
        $reason = $this->governanceReason ?? $data['governance_reason'] ?? null;

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:10']],
            [],
            ['reason' => 'reason for configuration request']
        )->validate();

        $this->governanceReason = $reason;
        $subProducts = config('sub_products', []);
        $changes = [];

        foreach ($subProducts as $key => $config) {
            $requestedEnabled = (bool) ($data["{$key}_enabled"] ?? false);
            $currentEnabled = $this->subProductService->isEnabled($key);

            if ($requestedEnabled !== $currentEnabled) {
                $changes[] = [
                    'type' => 'sub_product',
                    'sub_product' => $key,
                    'current_enabled' => $currentEnabled,
                    'requested_enabled' => $requestedEnabled,
                ];
            }

            foreach ($config['features'] ?? [] as $feature => $default) {
                $requestedFeatureEnabled = $requestedEnabled && (bool) ($data["{$key}_{$feature}"] ?? false);
                $currentFeatureEnabled = $this->subProductService->isFeatureEnabled($key, $feature);

                if ($requestedFeatureEnabled !== $currentFeatureEnabled) {
                    $changes[] = [
                        'type' => 'feature',
                        'sub_product' => $key,
                        'feature' => $feature,
                        'current_enabled' => $currentFeatureEnabled,
                        'requested_enabled' => $requestedFeatureEnabled,
                    ];
                }
            }
        }

        if ($changes === []) {
            Notification::make()
                ->title('No configuration changes detected')
                ->warning()
                ->send();

            return;
        }

        app(AdminActionGovernance::class)->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.sub_products.save',
            reason: (string) $reason,
            targetType: 'sub_product_configuration',
            targetIdentifier: 'all',
            payload: [
                'change_count' => count($changes),
                'changes' => $changes,
            ],
            metadata: [
                'actor_email' => auth()->user()->email ?? 'system',
                'sub_products' => array_values(array_unique(array_column($changes, 'sub_product'))),
            ],
        );

        Notification::make()
            ->title('Sub-product configuration request submitted')
            ->body('These changes now require approval before they are applied.')
            ->warning()
            ->send();

        Log::info(
            'Sub-product configuration change requested',
            [
                'user' => auth()->user()->email ?? 'system',
                'changes' => $changes,
            ]
        );

        $this->governanceReason = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('view_status')
                ->label('View API Status')
                ->icon('heroicon-o-eye')
                ->modalContent(
                    fn () => view(
                        'filament.modals.sub-product-status',
                        [
                            'status' => $this->subProductService->getApiStatus(),
                        ]
                    )
                )
                ->modalWidth('2xl'),
        ];
    }
}
