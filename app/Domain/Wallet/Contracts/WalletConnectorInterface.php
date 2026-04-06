<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

use App\Domain\Wallet\ValueObjects\BlockchainTransaction;
use App\Domain\Wallet\ValueObjects\WalletAddress;

interface WalletConnectorInterface
{
    /**
     * Generate a new wallet address.
     */
    public function generateAddress(string $blockchain, string $accountId): WalletAddress;

    /**
     * Get wallet balance from blockchain.
     */
    public function getBalance(string $blockchain, string $address): array;

    /**
     * Send transaction to blockchain.
     */
    public function sendTransaction(
        string $blockchain,
        string $fromAddress,
        string $toAddress,
        string $amount,
        array $options = []
    ): BlockchainTransaction;

    /**
     * Get transaction status.
     */
    public function getTransactionStatus(string $blockchain, string $transactionHash): array;

    /**
     * Monitor incoming transactions.
     */
    public function monitorIncomingTransactions(
        string $blockchain,
        string $address,
        int $fromBlock = 0
    ): array;

    /**
     * Validate address format.
     */
    public function validateAddress(string $blockchain, string $address): bool;

    /**
     * Get network fee estimate.
     */
    public function estimateNetworkFee(string $blockchain, string $priority = 'medium'): array;

    /**
     * Get supported blockchains.
     */
    public function getSupportedBlockchains(): array;
}
