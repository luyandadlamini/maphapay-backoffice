<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\User\Models\UserInvitation;
use App\Domain\Webhook\Models\Webhook;
use App\Filament\Admin\Pages\BroadcastNotificationPage;
use App\Filament\Admin\Pages\Modules;
use App\Filament\Admin\Pages\ProjectorHealthDashboard;
use App\Filament\Admin\Pages\SubProducts;
use App\Filament\Admin\Resources\ApiKeyResource;
use App\Filament\Admin\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Filament\Admin\Resources\AuditLogResource;
use App\Filament\Admin\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Admin\Resources\FeatureFlagResource;
use App\Filament\Admin\Resources\FeatureFlagResource\Pages\ListFeatureFlags;
use App\Filament\Admin\Resources\UserInvitationResource;
use App\Filament\Admin\Resources\UserInvitationResource\Pages\CreateUserInvitation;
use App\Filament\Admin\Resources\UserInvitationResource\Pages\ListUserInvitations;
use App\Filament\Admin\Resources\WebhookResource;
use App\Filament\Admin\Resources\WebhookResource\Pages\ListWebhooks;
use App\Infrastructure\Domain\DataObjects\DomainInfo;
use App\Infrastructure\Domain\DataObjects\VerificationResult;
use App\Infrastructure\Domain\DomainManager;
use App\Infrastructure\Domain\Enums\DomainStatus;
use App\Infrastructure\Domain\Enums\DomainType;
use App\Models\AdminActionApprovalRequest;
use App\Models\ApiKey;
use App\Models\Feature;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('hides modules and platform resources from finance operators', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $this->get(Modules::getUrl())->assertForbidden();

    expect(ApiKeyResource::canViewAny())->toBeFalse()
        ->and(FeatureFlagResource::canViewAny())->toBeFalse()
        ->and(ProjectorHealthDashboard::canAccess())->toBeFalse();
});

it('records governed audit metadata when a platform admin verifies a module', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    $domainManager = Mockery::mock(DomainManager::class);
    $domainManager->shouldReceive('verify')
        ->once()
        ->with('Treasury')
        ->andReturn(new VerificationResult(
            valid: true,
            domain: 'Treasury',
            checks: ['manifest_valid' => true, 'service_provider_exists' => true],
            warnings: ['Routes file is optional for this module.'],
        ));
    app()->instance(DomainManager::class, $domainManager);

    app(Modules::class)->verifyModule('Treasury', 'Post-deploy platform health verification.');

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.modules.verified')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('platform_administration')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Post-deploy platform health verification.')
        ->and($auditLog->metadata['module'] ?? null)->toBe('Treasury')
        ->and($auditLog->metadata['valid'] ?? null)->toBeTrue();
});

it('creates an approval request with evidence when enabling a module', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    $domainManager = Mockery::mock(DomainManager::class);
    $domainManager->shouldReceive('getAvailableDomains')
        ->andReturn(collect([
            new DomainInfo(
                name: 'Treasury',
                displayName: 'Treasury',
                description: 'Treasury controls',
                type: DomainType::OPTIONAL,
                status: DomainStatus::DISABLED,
                version: '1.0.0',
                dependencies: ['Ledger'],
                dependents: [],
            ),
        ]));
    app()->instance(DomainManager::class, $domainManager);

    app(Modules::class)->enableModule('Treasury', 'Enable treasury controls after cutover validation.');

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.modules.enable')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('platform_administration')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Enable treasury controls after cutover validation.')
        ->and($request->payload['module'] ?? null)->toBe('Treasury')
        ->and($request->payload['requested_state'] ?? null)->toBe('enabled')
        ->and($request->metadata['dependencies'] ?? null)->toBe(['Ledger']);
});

it('records governed audit metadata when revoking an api key', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $owner = User::factory()->create();
    $this->actingAs($platform);

    $apiKey = ApiKey::query()->create([
        'user_uuid'     => $owner->uuid,
        'name'          => 'Settlement key',
        'key_prefix'    => 'sett1234',
        'key_hash'      => bcrypt('secret'),
        'permissions'   => ['read', 'write'],
        'is_active'     => true,
        'request_count' => 0,
    ]);

    livewire(ListApiKeys::class)
        ->callTableAction('revoke', $apiKey, data: [
            'reason' => 'Credential rotation after privileged operator departure.',
        ]);

    expect($apiKey->fresh()?->is_active)->toBeFalse();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.api_keys.revoked')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->auditable_type)->toBe(ApiKey::class)
        ->and($auditLog->auditable_id)->toBe((string) $apiKey->getKey())
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('platform_administration')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Credential rotation after privileged operator departure.');
});

it('records governed audit metadata when toggling a feature flag', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    $feature = Feature::query()->create([
        'name'  => 'maintenance-mode',
        'scope' => 'global',
        'value' => false,
    ]);

    livewire(ListFeatureFlags::class)
        ->callTableAction('toggle', $feature, data: [
            'reason' => 'Enable maintenance mode for controlled release verification.',
        ]);

    expect($feature->fresh()?->isActive())->toBeTrue();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.feature_flags.toggled')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('platform_administration')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Enable maintenance mode for controlled release verification.')
        ->and($auditLog->metadata['flag'] ?? null)->toBe('maintenance-mode');
});

it('submits projector rebuild-all as an approval request instead of executing immediately', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    app(ProjectorHealthDashboard::class)->requestRebuildAll('Replay all projectors after projection schema correction.');

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.projectors.rebuild_all')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('platform_administration')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Replay all projectors after projection schema correction.')
        ->and($request->payload['scope'] ?? null)->toBe('all_projectors');
});

it('keeps platform resource components inaccessible to finance users', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(ApiKeyResource::canViewAny())->toBeFalse()
        ->and(FeatureFlagResource::canViewAny())->toBeFalse()
        ->and(ProjectorHealthDashboard::canAccess())->toBeFalse()
        ->and(WebhookResource::canViewAny())->toBeFalse()
        ->and(UserInvitationResource::canViewAny())->toBeFalse()
        ->and(AuditLogResource::canViewAny())->toBeFalse();
});

it('records governed audit metadata when testing a webhook', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    $webhook = Webhook::query()->create([
        'name'                 => 'Treasury Events',
        'url'                  => 'https://example.test/webhooks/treasury',
        'events'               => ['transfer.completed'],
        'is_active'            => true,
        'retry_attempts'       => 3,
        'timeout_seconds'      => 30,
        'consecutive_failures' => 0,
    ]);

    livewire(ListWebhooks::class)
        ->callTableAction('test', $webhook, data: [
            'reason' => 'Validate treasury webhook connectivity after credential rotation.',
        ]);

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.webhooks.tested')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->auditable_type)->toBe(Webhook::class)
        ->and($auditLog->auditable_id)->toBe((string) $webhook->getKey())
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('platform_administration')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Validate treasury webhook connectivity after credential rotation.')
        ->and($auditLog->metadata['webhook'] ?? null)->toBe('Treasury Events');
});

it('creates an approval request with evidence when deactivating a webhook', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    $webhook = Webhook::query()->create([
        'name'                 => 'Compliance Alerts',
        'url'                  => 'https://example.test/webhooks/compliance',
        'events'               => ['balance.low'],
        'is_active'            => true,
        'retry_attempts'       => 3,
        'timeout_seconds'      => 30,
        'consecutive_failures' => 0,
    ]);

    livewire(ListWebhooks::class)
        ->callTableAction('deactivate', $webhook, data: [
            'reason' => 'Pause downstream notifications pending vendor incident review.',
        ]);

    expect($webhook->fresh()?->is_active)->toBeTrue();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.webhooks.deactivate')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('platform_administration')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Pause downstream notifications pending vendor incident review.')
        ->and($request->target_type)->toBe(Webhook::class)
        ->and($request->target_identifier)->toBe((string) $webhook->getKey())
        ->and($request->payload['requested_state'] ?? null)->toBe('inactive');
});

it('records governed audit metadata when creating a user invitation', function (): void {
    Mail::fake();

    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    livewire(CreateUserInvitation::class)
        ->fillForm([
            'email'  => 'ops.manager@example.test',
            'role'   => 'admin',
            'reason' => 'Provision platform operations backup coverage for weekend support.',
        ])
        ->call('create');

    $invitation = UserInvitation::query()
        ->where('email', 'ops.manager@example.test')
        ->latest('created_at')
        ->first();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.user_invitations.created')
        ->latest('id')
        ->first();

    expect($invitation)->not->toBeNull()
        ->and($auditLog)->not->toBeNull()
        ->and($auditLog->auditable_type)->toBe(UserInvitation::class)
        ->and($auditLog->auditable_id)->toBe((string) $invitation->getKey())
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('platform_administration')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Provision platform operations backup coverage for weekend support.')
        ->and($auditLog->metadata['email'] ?? null)->toBe('ops.manager@example.test');
});

it('records governed audit metadata when copying an invitation link', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    $invitation = UserInvitation::query()->create([
        'email'      => 'security.admin@example.test',
        'token'      => str_repeat('a', 64),
        'role'       => 'admin',
        'invited_by' => $platform->id,
        'expires_at' => now()->addDay(),
    ]);

    livewire(ListUserInvitations::class)
        ->callTableAction('copyLink', $invitation, data: [
            'reason' => 'Share recovery invite with approved on-call platform operator.',
        ]);

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.user_invitations.link_copied')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->auditable_type)->toBe(UserInvitation::class)
        ->and($auditLog->auditable_id)->toBe((string) $invitation->getKey())
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('platform_administration')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Share recovery invite with approved on-call platform operator.')
        ->and($auditLog->metadata['email'] ?? null)->toBe('security.admin@example.test');
});

it('records governed audit metadata when exporting the audit trail', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    AuditLog::log(
        action: 'backoffice.settings.exported',
        metadata: ['seed' => true],
    );

    livewire(ListAuditLogs::class)
        ->callAction('exportAuditTrail', data: [
            'reason' => 'Prepare regulator-ready platform audit evidence package.',
        ]);

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.audit_logs.exported')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('platform_administration')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Prepare regulator-ready platform audit evidence package.')
        ->and($auditLog->metadata['export_scope'] ?? null)->toBe('all');
});

it('blocks finance operators from the platform webhook workspace gate', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(fn () => WebhookResource::authorizeWorkspace())
        ->toThrow(HttpException::class, 'This action is outside your workspace.');
});

it('blocks finance operators from the platform invitation workspace gate', function (): void {
    Mail::fake();

    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(fn () => UserInvitationResource::authorizeWorkspace())
        ->toThrow(HttpException::class, 'This action is outside your workspace.');
});

it('keeps broadcast notifications and sub-products inaccessible to finance users', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $this->get(BroadcastNotificationPage::getUrl())->assertForbidden();
    $this->get(SubProducts::getUrl())->assertForbidden();
});

it('records governed audit metadata when sending a broadcast notification', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $recipient = User::factory()->create();
    $this->actingAs($platform);

    app(BroadcastNotificationPage::class)->dispatchBroadcast([
        'channel'  => 'database',
        'audience' => 'user',
        'userId'   => $recipient->getKey(),
        'role'     => null,
        'subject'  => 'Platform maintenance notice',
        'body'     => 'Planned maintenance starts at 22:00 UTC.',
        'reason'   => 'Send an audited maintenance notice to the approved recipient.',
    ]);

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.broadcast_notifications.sent')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('platform_administration')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Send an audited maintenance notice to the approved recipient.')
        ->and($auditLog->metadata['channel'] ?? null)->toBe('database')
        ->and($auditLog->metadata['audience'] ?? null)->toBe('user')
        ->and($auditLog->metadata['recipient_count'] ?? null)->toBe(1)
        ->and($auditLog->metadata['subject'] ?? null)->toBe('Platform maintenance notice');
});

it('submits sub-product configuration changes for approval with captured evidence', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    config()->set('sub_products', [
        'treasury' => [
            'name'        => 'Treasury',
            'description' => 'Treasury controls',
            'icon'        => 'heroicon-o-banknotes',
            'licenses'    => ['treasury-license'],
            'features'    => [
                'sweeps'  => false,
                'hedging' => false,
            ],
        ],
    ]);

    $page = app(SubProducts::class);
    $page->boot();
    $page->mount();
    $page->data = [
        'treasury_enabled'  => true,
        'treasury_sweeps'   => true,
        'treasury_hedging'  => false,
        'governance_reason' => 'Enable treasury controls after platform readiness review.',
    ];
    $page->governanceReason = 'Enable treasury controls after platform readiness review.';

    $page->save();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.sub_products.save')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('platform_administration')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Enable treasury controls after platform readiness review.')
        ->and($request->payload['change_count'] ?? null)->toBe(2)
        ->and($request->payload['changes'][0]['type'] ?? null)->toBe('sub_product')
        ->and($request->payload['changes'][0]['sub_product'] ?? null)->toBe('treasury')
        ->and($request->payload['changes'][0]['requested_enabled'] ?? null)->toBeTrue()
        ->and($request->payload['changes'][1]['type'] ?? null)->toBe('feature')
        ->and($request->payload['changes'][1]['feature'] ?? null)->toBe('sweeps')
        ->and($request->payload['changes'][1]['requested_enabled'] ?? null)->toBeTrue()
        ->and($request->metadata['actor_email'] ?? null)->toBe($platform->email)
        ->and($request->metadata['sub_products'] ?? null)->toBe(['treasury']);
});

it('blocks finance operators from the platform broadcast workspace gate', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(fn () => app(BroadcastNotificationPage::class)->dispatchBroadcast([
        'channel'  => 'database',
        'audience' => 'all',
        'subject'  => 'Unauthorized',
        'body'     => 'This should not send.',
        'reason'   => 'Attempted unauthorized broadcast notification send.',
    ]))->toThrow(HttpException::class, 'This action is outside your workspace.');
});

it('blocks finance operators from the platform sub-products workspace gate', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    config()->set('sub_products', [
        'treasury' => [
            'name'        => 'Treasury',
            'description' => 'Treasury controls',
            'icon'        => 'heroicon-o-banknotes',
            'licenses'    => ['treasury-license'],
            'features'    => [
                'sweeps' => false,
            ],
        ],
    ]);

    $page = app(SubProducts::class);
    $page->boot();
    $page->mount();
    $page->data = [
        'treasury_enabled'  => true,
        'treasury_sweeps'   => true,
        'governance_reason' => 'Attempted unauthorized treasury configuration change.',
    ];
    $page->governanceReason = 'Attempted unauthorized treasury configuration change.';

    expect(fn () => $page->save())
        ->toThrow(HttpException::class, 'This action is outside your workspace.');
});
