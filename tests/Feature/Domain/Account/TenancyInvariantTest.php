<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Account;

use App\Domain\Account\Models\Account;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenancyInvariantTest extends TestCase
{
    #[Test]
    public function account_create_without_tenancy_throws_in_production(): void
    {
        // Temporarily set env to production for this test only
        config(['app.env' => 'production']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tenancy/i');

        Account::create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => (string) Str::uuid(),
            'name'      => 'should not work',
        ]);
    }
}
