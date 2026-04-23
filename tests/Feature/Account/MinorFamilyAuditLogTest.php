<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountAuditLog;
use App\Domain\Account\Services\MinorNotificationService;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class MinorFamilyAuditLogTest extends ControllerTestCase
{
    private MinorNotificationService $service;

    private User $actor;

    private Account $minorAccount;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('account_audit_logs')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant/2026_04_15_100003_create_account_audit_logs_table.php',
                '--force' => true,
            ]);
        }

        $this->service = app(MinorNotificationService::class);
        $this->actor = User::factory()->create();
        $this->minorAccount = Account::factory()->create([
            'user_uuid' => User::factory()->create()->uuid,
            'type' => 'minor',
        ]);
    }

    #[Test]
    public function it_persists_durable_audit_log_rows_for_phase_9a_minor_family_actions(): void
    {
        $cases = [
            MinorNotificationService::TYPE_FAMILY_FUNDING_LINK_CREATED => 'minor.family_funding_link.created',
            MinorNotificationService::TYPE_FAMILY_FUNDING_LINK_EXPIRED => 'minor.family_funding_link.expired',
            MinorNotificationService::TYPE_FAMILY_FUNDING_ATTEMPT_INITIATED => 'minor.family_funding_attempt.initiated',
            MinorNotificationService::TYPE_FAMILY_FUNDING_ATTEMPT_SUCCEEDED => 'minor.family_funding_attempt.succeeded',
            MinorNotificationService::TYPE_FAMILY_FUNDING_ATTEMPT_FAILED => 'minor.family_funding_attempt.failed',
            MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_INITIATED => 'minor.family_support_transfer.initiated',
            MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_SUCCEEDED => 'minor.family_support_transfer.succeeded',
            MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_FAILED => 'minor.family_support_transfer.failed',
            MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_REFUNDED => 'minor.family_support_transfer.refunded',
        ];

        foreach ($cases as $type => $action) {
            $targetId = (string) Str::uuid();

            $this->service->notify(
                minorAccountUuid: $this->minorAccount->uuid,
                type: $type,
                data: ['phase' => '9a'],
                actorUserUuid: $this->actor->uuid,
                targetType: 'minor_family_record',
                targetId: $targetId,
            );

            $log = AccountAuditLog::query()
                ->where('account_uuid', $this->minorAccount->uuid)
                ->where('action', $action)
                ->where('target_id', $targetId)
                ->latest('created_at')
                ->first();

            $this->assertNotNull($log);
            $this->assertSame($this->actor->uuid, $log->actor_user_uuid);
            $this->assertSame('minor_family_record', $log->target_type);
            $this->assertSame($type, $log->metadata['notification_type'] ?? null);
            $this->assertSame('9a', $log->metadata['phase'] ?? null);
        }
    }
}
