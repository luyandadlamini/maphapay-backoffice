<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Pages\BankOperations;
use App\Filament\Admin\Pages\Settings;
use App\Models\AdminActionApprovalRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BackofficeGovernancePagesTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePermissionTables();

        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

        app(SettingsService::class)->initializeSettings();

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setServingStatus(true);
        $panel->boot();
    }

    private function ensurePermissionTables(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'uuid')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
                $table->primary(['permission_id', 'model_id', 'model_type']);
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table): void {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
                $table->primary(['role_id', 'model_id', 'model_type']);
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('user_uuid')->nullable();
                $table->string('action');
                $table->string('auditable_type')->nullable();
                $table->string('auditable_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->string('tags')->nullable();
                $table->dateTime('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('admin_action_approval_requests')) {
            Schema::create('admin_action_approval_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('workspace');
                $table->string('action');
                $table->string('status')->default('pending');
                $table->text('reason');
                $table->unsignedBigInteger('requester_id')->nullable();
                $table->unsignedBigInteger('reviewer_id')->nullable();
                $table->string('target_type')->nullable();
                $table->string('target_identifier')->nullable();
                $table->json('payload')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_settings_page_is_restricted_to_platform_workspace_owners(): void
    {
        $finance = $this->backofficeUser('finance-lead');
        $this->actingAs($finance);

        $this->get(Settings::getUrl())
            ->assertForbidden();
    }

    public function test_bank_operations_page_is_hidden_from_support_roles(): void
    {
        $support = $this->backofficeUser('support-l1');
        $this->actingAs($support);

        $this->get(BankOperations::getUrl())
            ->assertForbidden();
    }

    public function test_settings_export_requires_evidence_and_records_a_governed_audit_entry(): void
    {
        $platform = $this->backofficeUser('super-admin');
        $this->actingAs($platform);

        $page = app(Settings::class);
        $page->boot();
        $page->mount();
        $page->exportSettings('Routine controlled platform configuration export.');

        $auditLog = AuditLog::query()
            ->where('action', 'backoffice.settings.exported')
            ->latest('id')
            ->first();

        self::assertNotNull($auditLog);
        self::assertSame('platform_administration', $auditLog->metadata['workspace'] ?? null);
        self::assertSame('direct_elevated', $auditLog->metadata['mode'] ?? null);
        self::assertSame('Routine controlled platform configuration export.', $auditLog->metadata['reason'] ?? null);
        self::assertArrayHasKey('filename', $auditLog->metadata ?? []);
    }

    public function test_settings_reset_creates_a_pending_approval_request_instead_of_executing_immediately(): void
    {
        $platform = $this->backofficeUser('super-admin');
        $this->actingAs($platform);

        $setting = Setting::where('key', 'platform_name')->firstOrFail();
        $setting->value = 'Changed Name';
        $setting->save();

        $page = app(Settings::class);
        $page->boot();
        $page->requestResetToDefaults('Emergency rollback request after control review.');

        self::assertSame('Changed Name', Setting::where('key', 'platform_name')->value('value'));

        $request = AdminActionApprovalRequest::query()
            ->where('action', 'backoffice.settings.reset_to_defaults')
            ->latest('id')
            ->first();

        self::assertNotNull($request);
        self::assertSame('pending', $request->status);
        self::assertSame('platform_administration', $request->workspace);
        self::assertSame('Emergency rollback request after control review.', $request->reason);
    }

    public function test_finance_workspace_manual_reconciliation_records_governed_audit_metadata(): void
    {
        $finance = $this->backofficeUser('finance-lead');
        $this->actingAs($finance);

        $page = app(BankOperations::class);
        $page->runManualRecon('DemoCustodian', 'Daily settlement mismatch review for ops cutover.');

        $auditLog = AuditLog::query()
            ->where('action', 'backoffice.bank_operations.manual_reconciliation_triggered')
            ->latest('id')
            ->first();

        self::assertNotNull($auditLog);
        self::assertSame('finance', $auditLog->metadata['workspace'] ?? null);
        self::assertSame('direct_elevated', $auditLog->metadata['mode'] ?? null);
        self::assertSame('DemoCustodian', $auditLog->metadata['custodian'] ?? null);
        self::assertSame('Daily settlement mismatch review for ops cutover.', $auditLog->metadata['reason'] ?? null);
    }

    public function test_settlement_freeze_creates_a_pending_approval_request_with_evidence(): void
    {
        $finance = $this->backofficeUser('finance-lead');
        $this->actingAs($finance);

        $page = app(BankOperations::class);
        $page->freezeBankSettlement('DemoCustodian', 'Freeze requested pending treasury incident review.');

        $request = AdminActionApprovalRequest::query()
            ->where('action', 'backoffice.bank_operations.freeze_settlement')
            ->latest('id')
            ->first();

        self::assertNotNull($request);
        self::assertSame('pending', $request->status);
        self::assertSame('finance', $request->workspace);
        self::assertSame('Freeze requested pending treasury incident review.', $request->reason);
        self::assertSame('DemoCustodian', $request->payload['custodian'] ?? null);
    }

    private function backofficeUser(string $role): User
    {
        $user = User::query()->create([
            'name'                   => ucfirst($role) . ' User',
            'email'                  => str_replace('_', '.', $role) . '.' . uniqid('', true) . '@example.test',
            'password'               => Hash::make('password'),
            'email_verified_at'      => now(),
            'kyc_status'             => 'not_started',
            'kyc_level'              => 'basic',
            'pep_status'             => false,
            'data_retention_consent' => false,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
