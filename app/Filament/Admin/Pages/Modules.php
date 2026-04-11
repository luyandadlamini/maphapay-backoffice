<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Infrastructure\Domain\DataObjects\DomainInfo;
use App\Infrastructure\Domain\DomainManager;
use App\Infrastructure\Domain\Enums\DomainStatus;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Exception;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;
use RuntimeException;

class Modules extends Page
{
    use HasBackofficeWorkspace;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Module Management';

    protected static string $view = 'filament.admin.pages.modules';

    protected static string $backofficeWorkspace = 'platform_administration';

    public static function canAccess(): bool
    {
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $typeFilter = '';

    private ?DomainManager $domainManager = null;

    public function getDomainManager(): DomainManager
    {
        if ($this->domainManager === null) {
            $this->domainManager = app(DomainManager::class);
        }

        return $this->domainManager;
    }

    protected function getAdminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    protected function authorizePlatformWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());
    }

    /**
     * Get all modules filtered by current search and status criteria.
     *
     * @return Collection<int, DomainInfo>
     */
    public function getModulesProperty(): Collection
    {
        $domains = $this->getDomainManager()->getAvailableDomains();

        if ($this->search !== '') {
            $search = mb_strtolower($this->search);
            $domains = $domains->filter(
                fn (DomainInfo $info) => str_contains(mb_strtolower($info->name), $search)
                    || str_contains(mb_strtolower($info->displayName), $search)
                    || str_contains(mb_strtolower($info->description), $search)
            );
        }

        if ($this->statusFilter !== '') {
            $status = DomainStatus::tryFrom($this->statusFilter);
            if ($status !== null) {
                $domains = $domains->filter(
                    fn (DomainInfo $info) => $info->status === $status
                );
            }
        }

        if ($this->typeFilter !== '') {
            $domains = $domains->filter(
                fn (DomainInfo $info) => $info->type->value === $this->typeFilter
            );
        }

        return $domains->sortBy('displayName')->values();
    }

    /**
     * Get summary statistics for the module overview.
     *
     * @return array<string, int>
     */
    public function getStatsProperty(): array
    {
        $domains = $this->getDomainManager()->getAvailableDomains();

        return [
            'total'     => $domains->count(),
            'installed' => $domains->filter(fn (DomainInfo $info) => $info->status === DomainStatus::INSTALLED)->count(),
            'available' => $domains->filter(fn (DomainInfo $info) => $info->status === DomainStatus::AVAILABLE)->count(),
            'disabled'  => $domains->filter(fn (DomainInfo $info) => $info->status === DomainStatus::DISABLED)->count(),
            'core'      => $domains->filter(fn (DomainInfo $info) => $info->type->isRequired())->count(),
            'optional'  => $domains->filter(fn (DomainInfo $info) => ! $info->type->isRequired())->count(),
        ];
    }

    /**
     * Check if a domain has declared routes.
     */
    public function hasRoutes(DomainInfo $info): bool
    {
        $manifests = $this->getDomainManager()->loadAllManifests();

        if (! isset($manifests[$info->name])) {
            return false;
        }

        return $manifests[$info->name]->getPath('routes') !== null;
    }

    /**
     * Enable a disabled domain.
     */
    public function enableModule(string $domain, string $reason): void
    {
        $this->authorizePlatformWorkspace();
        $this->validateGovernanceReason($reason);

        $module = $this->findModule($domain);

        $this->getAdminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.modules.enable',
            reason: $reason,
            targetType: 'domain_module',
            targetIdentifier: $domain,
            payload: [
                'module' => $domain,
                'requested_state' => 'enabled',
                'current_status' => $module->status->value,
            ],
            metadata: [
                'dependencies' => $module->dependencies,
                'actor_email' => auth()->user()->email ?? 'system',
            ],
        );

        Notification::make()
            ->title('Enable request submitted')
            ->body("{$domain} now requires approval before it can be enabled.")
            ->warning()
            ->send();
    }

    /**
     * Disable an active domain.
     */
    public function disableModule(string $domain, string $reason): void
    {
        $this->authorizePlatformWorkspace();
        $this->validateGovernanceReason($reason);

        $module = $this->findModule($domain);

        $this->getAdminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.modules.disable',
            reason: $reason,
            targetType: 'domain_module',
            targetIdentifier: $domain,
            payload: [
                'module' => $domain,
                'requested_state' => 'disabled',
                'current_status' => $module->status->value,
            ],
            metadata: [
                'dependents' => $module->dependents,
                'actor_email' => auth()->user()->email ?? 'system',
            ],
        );

        Notification::make()
            ->title('Disable request submitted')
            ->body("{$domain} now requires approval before it can be disabled.")
            ->warning()
            ->send();
    }

    /**
     * Verify a domain's health and configuration.
     */
    public function verifyModule(string $domain, string $reason): void
    {
        $this->authorizePlatformWorkspace();
        $this->validateGovernanceReason($reason);

        try {
            $result = $this->getDomainManager()->verify($domain);

            $this->getAdminActionGovernance()->auditDirectAction(
                workspace: static::getBackofficeWorkspace(),
                action: 'backoffice.modules.verified',
                reason: $reason,
                metadata: [
                    'module' => $domain,
                    'valid' => $result->valid,
                    'checks' => Arr::sort($result->checks),
                    'errors' => $result->errors,
                    'warnings' => $result->warnings,
                    'passed_checks' => $result->getPassedCount(),
                    'failed_checks' => $result->getFailedCount(),
                    'actor_email' => auth()->user()->email ?? 'system',
                ],
                tags: 'backoffice,platform,module-verification'
            );

            if ($result->valid) {
                $totalChecks = count($result->checks);
                $message = "Verified: {$result->getPassedCount()}/{$totalChecks} checks passed.";

                if (! empty($result->warnings)) {
                    $message .= ' Warnings: ' . implode(', ', $result->warnings);
                }

                Notification::make()
                    ->title('Verification Passed')
                    ->body($message)
                    ->success()
                    ->duration(8000)
                    ->send();
            } else {
                $totalChecks = count($result->checks);
                $message = "Failed: {$result->getPassedCount()}/{$totalChecks} checks passed.";

                if (! empty($result->errors)) {
                    $message .= ' Errors: ' . implode(', ', $result->errors);
                }

                Notification::make()
                    ->title('Verification Failed')
                    ->body($message)
                    ->danger()
                    ->duration(10000)
                    ->send();
            }

            Log::info('Module verified via admin panel', [
                'domain' => $domain,
                'valid'  => $result->valid,
                'passed' => $result->getPassedCount(),
                'failed' => $result->getFailedCount(),
                'user'   => auth()->user()->email ?? 'system',
            ]);
        } catch (Exception $e) {
            Notification::make()
                ->title('Error')
                ->body("Failed to verify module: {$e->getMessage()}")
                ->danger()
                ->send();

            Log::error('Module verification failed', [
                'domain' => $domain,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear the domain manager cache and refresh the page.
     */
    public function refreshModules(): void
    {
        $this->getDomainManager()->clearCache();

        Notification::make()
            ->title('Cache Cleared')
            ->body('Module cache has been refreshed.')
            ->success()
            ->send();
    }

    /**
     * Reset all filters to defaults.
     */
    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->typeFilter = '';
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('refresh')
                ->label('Refresh Cache')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->refreshModules()),
        ];
    }

    private function validateGovernanceReason(string $reason): void
    {
        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:10']]
        )->validate();
    }

    private function findModule(string $domain): DomainInfo
    {
        /** @var DomainInfo|null $module */
        $module = $this->getDomainManager()
            ->getAvailableDomains()
            ->first(fn (DomainInfo $info): bool => $info->name === $domain);

        if ($module === null) {
            throw new RuntimeException("Module {$domain} is not available in the current inventory.");
        }

        return $module;
    }
}
