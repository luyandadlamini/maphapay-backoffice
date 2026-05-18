<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\MobilePayment;

use App\Domain\MobilePayment\Models\PaymentIntent;

it('keeps payment intents on the central connection inside tenant admin pages', function (): void {
    expect((new PaymentIntent())->getConnectionName())->toBe('central');
});
