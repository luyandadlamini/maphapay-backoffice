<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services;

use App\Domain\MachinePay\Contracts\PaymentRailInterface;

/**
 * Resolves payment rail adapters by identifier.
 *
 * Uses the DI container to resolve tagged rail adapters
 * and match them to the requested rail string.
 */
class MppRailResolverService
{
    /** @var array<string, PaymentRailInterface> */
    private array $rails = [];

    /**
     * Register a rail adapter.
     */
    public function register(PaymentRailInterface $rail): void
    {
        $this->rails[$rail->getRailIdentifier()->value] = $rail;
    }

    /**
     * Resolve a rail adapter by its string identifier.
     */
    public function resolve(string $railId): ?PaymentRailInterface
    {
        return $this->rails[$railId] ?? null;
    }

    /**
     * Get all registered and available rails.
     *
     * @return array<string, PaymentRailInterface>
     */
    public function getAvailableRails(): array
    {
        return array_filter(
            $this->rails,
            static fn (PaymentRailInterface $rail): bool => $rail->isAvailable(),
        );
    }

    /**
     * Get the identifiers of all available rails.
     *
     * @return array<string>
     */
    public function getAvailableRailIds(): array
    {
        return array_keys($this->getAvailableRails());
    }

    /**
     * Check if a specific rail is available.
     */
    public function isRailAvailable(string $railId): bool
    {
        $rail = $this->rails[$railId] ?? null;

        return $rail !== null && $rail->isAvailable();
    }
}
