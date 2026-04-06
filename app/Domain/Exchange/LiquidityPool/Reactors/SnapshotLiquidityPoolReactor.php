<?php

declare(strict_types=1);

namespace App\Domain\Exchange\LiquidityPool\Reactors;

use App\Domain\Exchange\Aggregates\LiquidityPool;
use App\Domain\Exchange\Events\LiquidityAdded;
use App\Domain\Exchange\Events\LiquidityPoolRebalanced;
use App\Domain\Exchange\Events\LiquidityRemoved;
use App\Domain\Exchange\Events\LiquidityRewardsDistributed;
use Brick\Math\BigDecimal;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class SnapshotLiquidityPoolReactor extends Reactor
{
    private const EVENTS_THRESHOLD = 100;

    private const TVL_CHANGE_THRESHOLD = '1000000'; // $1M TVL change

    private const REBALANCE_THRESHOLD = 5; // Snapshot after 5 rebalances

    private array $eventCounts = [];

    private array $rebalanceCounts = [];

    /**
     * Take snapshot when significant liquidity is added.
     */
    public function onLiquidityAdded(LiquidityAdded $event): void
    {
        $this->checkEventThreshold($event->poolId);

        // Check if TVL change is significant
        $baseAmount = BigDecimal::of($event->baseAmount);
        $quoteAmount = BigDecimal::of($event->quoteAmount);

        // Assuming USD value approximation
        if ($quoteAmount->isGreaterThan(self::TVL_CHANGE_THRESHOLD)) {
            $this->takeSnapshot($event->poolId);
        }
    }

    /**
     * Take snapshot when significant liquidity is removed.
     */
    public function onLiquidityRemoved(LiquidityRemoved $event): void
    {
        $this->checkEventThreshold($event->poolId);

        // Check if TVL change is significant
        $quoteAmount = BigDecimal::of($event->quoteAmount);

        if ($quoteAmount->isGreaterThan(self::TVL_CHANGE_THRESHOLD)) {
            $this->takeSnapshot($event->poolId);
        }
    }

    /**
     * Take snapshot after multiple rebalances.
     */
    public function onLiquidityPoolRebalanced(LiquidityPoolRebalanced $event): void
    {
        $poolId = $event->poolId;

        if (! isset($this->rebalanceCounts[$poolId])) {
            $this->rebalanceCounts[$poolId] = 0;
        }

        $this->rebalanceCounts[$poolId]++;

        if ($this->rebalanceCounts[$poolId] >= self::REBALANCE_THRESHOLD) {
            $this->takeSnapshot($poolId);
            $this->rebalanceCounts[$poolId] = 0;
        }
    }

    /**
     * Take snapshot after reward distributions.
     */
    public function onLiquidityRewardsDistributed(LiquidityRewardsDistributed $event): void
    {
        $this->checkEventThreshold($event->poolId);

        // Large reward distributions warrant a snapshot
        $rewardAmount = BigDecimal::of($event->rewardAmount);
        if ($rewardAmount->isGreaterThan('100000')) { // $100k rewards
            $this->takeSnapshot($event->poolId);
        }
    }

    /**
     * Check if event count threshold is reached.
     */
    private function checkEventThreshold(string $poolId): void
    {
        if (! isset($this->eventCounts[$poolId])) {
            $this->eventCounts[$poolId] = 0;
        }

        $this->eventCounts[$poolId]++;

        if ($this->eventCounts[$poolId] >= self::EVENTS_THRESHOLD) {
            $this->takeSnapshot($poolId);
            $this->eventCounts[$poolId] = 0;
        }
    }

    /**
     * Take a snapshot of the liquidity pool.
     */
    protected function takeSnapshot(string $poolId): void
    {
        $aggregate = LiquidityPool::retrieve($poolId);
        $aggregate->snapshot();
    }
}
