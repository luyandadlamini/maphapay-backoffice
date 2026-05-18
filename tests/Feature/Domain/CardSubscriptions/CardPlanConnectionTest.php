<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CardSubscriptions;

use App\Domain\CardSubscriptions\Models\CardPlan;

it('keeps card plans on the central connection inside tenant admin pages', function (): void {
    expect((new CardPlan())->getConnectionName())->toBe('central');
});
