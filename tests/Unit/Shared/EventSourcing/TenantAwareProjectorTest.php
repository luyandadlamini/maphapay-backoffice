<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\EventSourcing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Spatie\EventSourcing\StoredEvents\StoredEvent;
use Stancl\Tenancy\Tenancy;
use Tests\Fixtures\EventSourcing\TestPlainEvent;
use Tests\Fixtures\EventSourcing\TestPlainEventProjector;
use Tests\Fixtures\EventSourcing\TestTenantAwareProjector;
use Tests\Fixtures\EventSourcing\TestTenantCarryingEvent;
use Tests\TestCase;
use Throwable;

class TenantAwareProjectorTest extends TestCase
{
    /**
     * Disable DB transaction wrapping — tenancy initialization switches DB connections,
     * which is incompatible with wrapping everything in a single transaction.
     *
     * @return array<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    /** @var list<array{0:string,1:string}> [$accountUuid,] pairs seeded in this test. */
    private array $seeded = [];

    protected function tearDown(): void
    {
        $tenancy = app(Tenancy::class);
        if ($tenancy->initialized) {
            $tenancy->end();
        }

        try {
            DB::connection('central')->reconnect();
            foreach ($this->seeded as [$accountUuid, $tenantId]) {
                DB::connection('central')->table('account_memberships')->where('account_uuid', $accountUuid)->delete();
                DB::connection('central')->table('users')->where('uuid', $accountUuid)->delete();
                DB::connection('central')->table('tenants')->where('id', $tenantId)->delete();
            }
        } catch (Throwable) {
            // best-effort
        }
        $this->seeded = [];

        parent::tearDown();
    }

    /**
     * Seeds a tenant + user + membership row directly in the central DB without firing
     * Stancl tenant-creation events (which would attempt to CREATE the tenant DB).
     * Returns the account UUID that WithTenantContext::withAccountTenancy() can resolve.
     */
    private function seedTenantAndMembership(): string
    {
        $tenantId = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();
        $userUuid = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id'         => $tenantId,
            'name'       => 'TenantAwareProjector test tenant',
            'plan'       => 'default',
            'data'       => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // account_memberships.user_uuid has a FK to users.uuid — seed a minimal user.
        DB::connection('central')->table('users')->insert([
            'uuid'       => $userUuid,
            'name'       => 'Test User',
            'email'      => 'tap-test-' . $userUuid . '@example.test',
            'password'   => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('central')->table('account_memberships')->insert([
            'id'           => (string) Str::uuid(),
            'user_uuid'    => $userUuid,
            'tenant_id'    => $tenantId,
            'account_uuid' => $accountUuid,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->seeded[] = [$accountUuid, $tenantId];

        return $accountUuid;
    }

    /**
     * Build a Spatie StoredEvent value object wrapping the given domain event.
     * Passes $event as the originalEvent so that no serialization/deserialization occurs.
     */
    private function makeStoredEvent(ShouldBeStored $event): StoredEvent
    {
        return new StoredEvent(
            [
                'id'                => null,
                'event_properties'  => [],
                'aggregate_uuid'    => (string) Str::uuid(),
                'aggregate_version' => '1',
                'event_version'     => 1,
                'event_class'       => get_class($event),
                'meta_data'         => [],
                'created_at'        => now()->toDateTimeString(),
            ],
            $event,
        );
    }

    public function test_handler_runs_under_tenant_context_when_event_carries_it(): void
    {
        if ($this->isInMemorySqlite()) {
            $this->markTestSkipped('Requires MySQL — SQLite :memory: cannot share tables across connections.');
        }

        $accountUuid = $this->seedTenantAndMembership();

        $event = new TestTenantCarryingEvent($accountUuid);
        $storedEvent = $this->makeStoredEvent($event);
        $projector = new TestTenantAwareProjector();

        $projector->handle($storedEvent);

        $this->assertTrue(
            $projector->capturedTenancyState,
            'Handler must execute with tenancy initialized when the event carries tenant context.',
        );

        $this->assertFalse(
            app(Tenancy::class)->initialized,
            'Tenancy must be torn down after the handler returns.',
        );
    }

    public function test_handler_runs_without_tenancy_when_event_does_not_carry_context(): void
    {
        $event = new TestPlainEvent();
        $storedEvent = $this->makeStoredEvent($event);
        $projector = new TestPlainEventProjector();

        $projector->handle($storedEvent);

        $this->assertNotNull(
            $projector->capturedTenancyState,
            'onTestPlainEvent() must have been invoked.',
        );

        $this->assertFalse(
            $projector->capturedTenancyState,
            'Tenancy must NOT be initialized when handling a tenant-agnostic event.',
        );
    }
}
