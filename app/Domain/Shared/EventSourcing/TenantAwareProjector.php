<?php

declare(strict_types=1);

namespace App\Domain\Shared\EventSourcing;

use App\Domain\Shared\Concerns\WithTenantContext;
use App\Domain\Shared\Contracts\CarriesTenantContext;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\StoredEvent;

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

        if ($event instanceof CarriesTenantContext) {
            $this->withAccountTenancy(
                $event->tenantAccountUuid(),
                fn () => parent::handle($storedEvent),
            );

            return;
        }

        parent::handle($storedEvent);
    }
}
