<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Services;

use App\Domain\CardSubscriptions\Services\CardAuditService;
use InvalidArgumentException;
use Tests\TestCase;

class CardAuditServicePanMetadataTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    public function test_rejects_metadata_containing_thirteen_digit_run(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $service = app(CardAuditService::class);

        $service->record(
            actorType: 'user',
            actorId: '1',
            action: 'test.action',
            entityType: 'TestEntity',
            entityId: '1',
            beforeState: null,
            afterState: null,
            metadata: [
                'notes' => 'Customer said PAN is 4111111111111',
            ],
        );
    }

    public function test_allows_metadata_without_digit_runs(): void
    {
        $service = app(CardAuditService::class);

        $log = $service->record(
            actorType: 'user',
            actorId: '1',
            action: 'test.action',
            entityType: 'TestEntity',
            entityId: '1',
            beforeState: null,
            afterState: null,
            metadata: [
                'notes' => 'Customer verified last4 only: 4242',
            ],
        );

        $this->assertSame('test.action', $log->action);
    }
}
