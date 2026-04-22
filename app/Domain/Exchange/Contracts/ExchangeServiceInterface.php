<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

interface ExchangeServiceInterface
{
    /**
     * Place a new order on the exchange.
     */
    public function placeOrder(
        string|int $accountId,
        string $type,
        string $orderType,
        string $baseCurrency,
        string $quoteCurrency,
        string $amount,
        ?string $price = null,
        ?string $stopPrice = null,
        array $metadata = []
    ): array;

    /**
     * Cancel an existing order.
     */
    public function cancelOrder(string $orderId, string $reason = 'User requested'): array;

    /**
     * Get order book data for a currency pair.
     */
    public function getOrderBook(string $baseCurrency, string $quoteCurrency, int $depth = 20): array;

    /**
     * Get market data and statistics for a currency pair.
     */
    public function getMarketData(string $baseCurrency, string $quoteCurrency): array;
}
