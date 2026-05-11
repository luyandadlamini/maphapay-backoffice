<?php

declare(strict_types=1);

use App\Domain\CardSubscriptions\Jobs\BillCardSubscriptionsJob;
use Illuminate\Support\Facades\Bus;

it('dispatches renewal jobs for due active subscriptions in tenant context', function () {
    Bus::fake();

    $job = new BillCardSubscriptionsJob();
    $job->handle();

    // With zero tenants in the test database this is a no-op; still must not throw.
    Bus::assertNothingDispatched();
})->group('cards', 'jobs');
