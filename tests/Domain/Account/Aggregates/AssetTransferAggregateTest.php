<?php

declare(strict_types=1);

namespace Tests\Domain\Account\Aggregates;

use App\Domain\Account\Aggregates\AssetTransferAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Events\AssetTransferred;
use App\Domain\Account\Events\TransferThresholdReached;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;
use Tests\DomainTestCase;

class AssetTransferAggregateTest extends DomainTestCase
{
    protected string $transferUuid;

    protected AccountUuid $fromAccount;

    protected AccountUuid $toAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transferUuid = (string) Str::uuid();
        $this->fromAccount = new AccountUuid((string) Str::uuid());
        $this->toAccount = new AccountUuid((string) Str::uuid());
    }

    #[Test]
    public function it_is_an_aggregate_root()
    {
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);

        expect($aggregate)->toBeInstanceOf(AggregateRoot::class);
        expect($aggregate)->toBeInstanceOf(AssetTransferAggregate::class);
    }

    #[Test]
    public function it_can_transfer_assets()
    {
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);

        $aggregate->transfer(
            from: $this->fromAccount,
            to: $this->toAccount,
            assetCode: 'USD',
            amount: 5000
        );

        $events = $aggregate->getRecordedEvents();
        expect($events)->toHaveCount(1);
        expect($events[0])->toBeInstanceOf(AssetTransferred::class);
        expect($events[0]->from)->toBe($this->fromAccount);
        expect($events[0]->to)->toBe($this->toAccount);
        expect($events[0]->assetCode)->toBe('USD');
        expect($events[0]->amount)->toBe(5000);
    }

    #[Test]
    public function it_can_transfer_with_metadata()
    {
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);

        $metadata = [
            'reference'   => 'INV-2024-001',
            'description' => 'Payment for invoice',
            'category'    => 'business',
        ];

        $aggregate->transfer(
            from: $this->fromAccount,
            to: $this->toAccount,
            assetCode: 'EUR',
            amount: 10000,
            metadata: $metadata
        );

        $events = $aggregate->getRecordedEvents();
        expect($events[0]->metadata)->toBe($metadata);
    }

    #[Test]
    public function it_can_transfer_different_assets()
    {
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);

        // Transfer USD
        $aggregate->transfer(
            from: $this->fromAccount,
            to: $this->toAccount,
            assetCode: 'USD',
            amount: 5000
        );

        $aggregate->persist();

        // Transfer EUR
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);
        $aggregate->transfer(
            from: $this->fromAccount,
            to: $this->toAccount,
            assetCode: 'EUR',
            amount: 3000
        );

        // Transfer BTC
        $aggregate->transfer(
            from: $this->fromAccount,
            to: $this->toAccount,
            assetCode: 'BTC',
            amount: 10000000 // 0.1 BTC in satoshis
        );

        $events = $aggregate->getRecordedEvents();
        expect($events)->toHaveCount(2);
        expect($events[0]->assetCode)->toBe('EUR');
        expect($events[1]->assetCode)->toBe('BTC');
    }

    #[Test]
    /**
     * @group slow
     */
    public function it_triggers_threshold_event_after_many_transfers()
    {
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);

        // Transfer 999 times
        for ($i = 0; $i < 999; $i++) {
            $aggregate->transfer(
                from: $this->fromAccount,
                to: $this->toAccount,
                assetCode: 'USD',
                amount: 1
            );
        }

        expect($aggregate->getRecordedEvents())->toHaveCount(999);

        // The 1000th transfer should trigger threshold event
        $aggregate->transfer(
            from: $this->fromAccount,
            to: $this->toAccount,
            assetCode: 'USD',
            amount: 1
        );

        $events = $aggregate->getRecordedEvents();
        expect($events)->toHaveCount(1001); // 1000 transfers + 1 threshold event
        expect($events[1000])->toBeInstanceOf(TransferThresholdReached::class);
    }

    #[Test]
    /**
     * @group slow
     */
    public function it_resets_count_after_threshold_reached()
    {
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);

        // Transfer 1000 times to trigger threshold
        for ($i = 0; $i < 1000; $i++) {
            $aggregate->transfer(
                from: $this->fromAccount,
                to: $this->toAccount,
                assetCode: 'USD',
                amount: 1
            );
        }

        $aggregate->persist();

        // Retrieve and add one more transfer
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);
        $aggregate->transfer(
            from: $this->fromAccount,
            to: $this->toAccount,
            assetCode: 'USD',
            amount: 1
        );

        // Should only have 1 new event, not trigger threshold again
        $events = $aggregate->getRecordedEvents();
        expect($events)->toHaveCount(1);
        expect($events[0])->toBeInstanceOf(AssetTransferred::class);
    }

    #[Test]
    public function it_includes_hash_for_security()
    {
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);

        $aggregate->transfer(
            from: $this->fromAccount,
            to: $this->toAccount,
            assetCode: 'USD',
            amount: 5000
        );

        $events = $aggregate->getRecordedEvents();
        expect($events[0]->hash)->not->toBeNull();
        expect($events[0]->hash->getHash())->toHaveLength(128); // SHA3-512 produces 128 char hex string
    }

    #[Test]
    public function it_can_transfer_between_different_account_pairs()
    {
        $aggregate = AssetTransferAggregate::retrieve($this->transferUuid);

        $account1 = new AccountUuid((string) Str::uuid());
        $account2 = new AccountUuid((string) Str::uuid());
        $account3 = new AccountUuid((string) Str::uuid());

        // Transfer from account1 to account2
        $aggregate->transfer(
            from: $account1,
            to: $account2,
            assetCode: 'USD',
            amount: 1000
        );

        // Transfer from account2 to account3
        $aggregate->transfer(
            from: $account2,
            to: $account3,
            assetCode: 'USD',
            amount: 500
        );

        // Transfer from account1 to account3
        $aggregate->transfer(
            from: $account1,
            to: $account3,
            assetCode: 'EUR',
            amount: 750
        );

        $events = $aggregate->getRecordedEvents();
        expect($events)->toHaveCount(3);

        // Verify first transfer
        expect($events[0]->from)->toBe($account1);
        expect($events[0]->to)->toBe($account2);
        expect($events[0]->assetCode)->toBe('USD');
        expect($events[0]->amount)->toBe(1000);

        // Verify second transfer
        expect($events[1]->from)->toBe($account2);
        expect($events[1]->to)->toBe($account3);
        expect($events[1]->assetCode)->toBe('USD');
        expect($events[1]->amount)->toBe(500);

        // Verify third transfer
        expect($events[2]->from)->toBe($account1);
        expect($events[2]->to)->toBe($account3);
        expect($events[2]->assetCode)->toBe('EUR');
        expect($events[2]->amount)->toBe(750);
    }
}
