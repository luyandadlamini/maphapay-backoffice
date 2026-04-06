<?php

declare(strict_types=1);

namespace App\Domain\Asset\Aggregates;

use App\Domain\Asset\Events\BasketComposed;
use App\Domain\Asset\Events\BasketCreated;
use App\Domain\Asset\Events\BasketDecomposed;
use App\Domain\Asset\Events\BasketRebalanced;
use App\Domain\Asset\Events\BasketValueCalculated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class BasketAggregate extends AggregateRoot
{
    private string $basketCode;

    private string $name;

    private string $type;

    private array $components = [];

    private bool $isActive = true;

    private ?string $rebalanceFrequency = null;

    public function createBasket(
        string $basketCode,
        string $name,
        string $type,
        array $components,
        ?string $rebalanceFrequency = null
    ): self {
        $this->recordThat(
            new BasketCreated(
                $basketCode,
                $name,
                $type,
                $components,
                $rebalanceFrequency
            )
        );

        return $this;
    }

    public function composeBasket(
        string $accountUuid,
        string $basketCode,
        int $amount,
        array $exchangeRates
    ): self {
        $this->recordThat(
            new BasketComposed(
                $accountUuid,
                $basketCode,
                $amount,
                $exchangeRates,
                $this->components
            )
        );

        return $this;
    }

    public function decomposeBasket(
        string $accountUuid,
        string $basketCode,
        int $amount,
        array $exchangeRates
    ): self {
        $this->recordThat(
            new BasketDecomposed(
                $accountUuid,
                $basketCode,
                $amount,
                $exchangeRates,
                $this->components
            )
        );

        return $this;
    }

    public function rebalanceBasket(
        string $basketCode,
        array $newComponents,
        array $oldComponents
    ): self {
        $this->recordThat(
            new BasketRebalanced(
                $basketCode,
                $newComponents,
                $oldComponents
            )
        );

        return $this;
    }

    public function calculateValue(
        string $basketCode,
        array $exchangeRates,
        float $totalValue
    ): self {
        $this->recordThat(
            new BasketValueCalculated(
                $basketCode,
                $exchangeRates,
                $totalValue,
                now()
            )
        );

        return $this;
    }

    protected function applyBasketCreated(BasketCreated $event): void
    {
        $this->basketCode = $event->basketCode;
        $this->name = $event->name;
        $this->type = $event->type;
        $this->components = $event->components;
        $this->rebalanceFrequency = $event->rebalanceFrequency;
    }

    protected function applyBasketComposed(BasketComposed $event): void
    {
        // Basket composition completed
    }

    protected function applyBasketDecomposed(BasketDecomposed $event): void
    {
        // Basket decomposition completed
    }

    protected function applyBasketRebalanced(BasketRebalanced $event): void
    {
        $this->components = $event->newComponents;
    }

    protected function applyBasketValueCalculated(BasketValueCalculated $event): void
    {
        // Value calculation completed
    }
}
