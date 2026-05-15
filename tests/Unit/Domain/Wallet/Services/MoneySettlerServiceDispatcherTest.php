<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Services;

use App\Domain\Wallet\Contracts\ProviderSettler;
use App\Domain\Wallet\Services\MoneySettlerService;
use Tests\TestCase;

final class MoneySettlerServiceDispatcherTest extends TestCase
{
    public function test_dispatches_to_settler_matching_provider_id(): void
    {
        $emaliSettler = $this->makeSpySettler('emali_eswatini_mobile');
        $mtnSettler = $this->makeSpySettler('mtn_momo');

        $dispatcher = new MoneySettlerService([$emaliSettler, $mtnSettler]);

        $dispatcher->settle('mtn_momo', 'ref-1', 'SUCCESSFUL', ['referenceId' => 'ref-1']);

        $this->assertSame(1, $mtnSettler->calls);
        $this->assertSame(0, $emaliSettler->calls);
        $this->assertSame('ref-1', $mtnSettler->lastRequestId);
        $this->assertSame('SUCCESSFUL', $mtnSettler->lastOutcome);
    }

    public function test_unknown_provider_is_no_op(): void
    {
        $mtnSettler = $this->makeSpySettler('mtn_momo');

        $dispatcher = new MoneySettlerService([$mtnSettler]);

        $dispatcher->settle('does_not_exist', 'ref-2', 'SUCCESSFUL', []);

        $this->assertSame(0, $mtnSettler->calls);
    }

    public function test_register_adds_settler_at_runtime(): void
    {
        $dispatcher = new MoneySettlerService([]);
        $settler = $this->makeSpySettler('fnb_ewallet');

        $dispatcher->register($settler);
        $dispatcher->settle('fnb_ewallet', 'ref-3', 'SUCCESSFUL', []);

        $this->assertSame(1, $settler->calls);
    }

    private function makeSpySettler(string $providerId): object
    {
        return new class ($providerId) implements ProviderSettler {
            public int $calls = 0;

            public string $lastRequestId = '';

            public string $lastOutcome = '';

            public function __construct(private readonly string $providerId)
            {
            }

            public function providerId(): string
            {
                return $this->providerId;
            }

            /**
             * @param  array<string, mixed>  $payload
             */
            public function settle(string $providerRequestId, string $outcome, array $payload): void
            {
                $this->calls++;
                $this->lastRequestId = $providerRequestId;
                $this->lastOutcome = $outcome;
            }
        };
    }
}
