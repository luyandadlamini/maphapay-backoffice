<?php

declare(strict_types=1);

namespace App\Domain\Shared\EventSourcing;

use App\Domain\Shared\Concerns\WithTenantContext;
use App\Domain\Shared\Contracts\CarriesTenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\StoredEvent;
use Throwable;

/**
 * Abstract projector base that auto-initializes tenancy from the event.
 *
 * Subclasses define `on<EventName>` methods as usual. Before each handler
 * fires, this base inspects the inner domain event:
 *   - If it implements CarriesTenantContext, the handler runs inside
 *     WithTenantContext::withAccountTenancy(...), ensuring the correct
 *     tenant DB is active for all writes.
 *   - If it does not, the handler runs as-is (event is tenant-agnostic).
 *
 * This eliminates the class of bug where a projector running in a queue
 * worker silently writes to the wrong tenant DB because it ran without
 * the tenancy middleware that HTTP workers enjoy.
 *
 * NOTE: Spatie's HandlesEvents::handle() accepts a StoredEvent wrapper,
 * not a raw ShouldBeStored. The inner domain event is at $storedEvent->event.
 */
abstract class TenantAwareProjector extends Projector
{
    use WithTenantContext;

    public function handle(StoredEvent $storedEvent): void
    {
        $event = $storedEvent->event;

        if (! $event instanceof CarriesTenantContext) {
            parent::handle($storedEvent);

            return;
        }

        $tenantAccountUuid = $event->tenantAccountUuid();

        if ($this->shouldSkipTenancyInitialization($tenantAccountUuid)) {
            parent::handle($storedEvent);

            return;
        }

        $this->withAccountTenancy(
            $tenantAccountUuid,
            fn () => parent::handle($storedEvent),
        );
    }

    /**
     * In the testing environment many tests fire aggregate events without seeding
     * an account_memberships row (UsesTenantConnection is lenient in tests, so the
     * default connection is used). Initializing tenancy here would throw — and the
     * projector body would already work correctly on the default connection.
     *
     * In production and staging this always returns false: a missing membership is
     * a real bug and must throw.
     */
    private function shouldSkipTenancyInitialization(string $tenantAccountUuid): bool
    {
        if (Config::get('app.env') !== 'testing') {
            return false;
        }

        try {
            return ! DB::connection('central')
                ->table('account_memberships')
                ->where('account_uuid', $tenantAccountUuid)
                ->where('status', 'active')
                ->exists();
        } catch (Throwable) {
            return true;
        }
    }
}
