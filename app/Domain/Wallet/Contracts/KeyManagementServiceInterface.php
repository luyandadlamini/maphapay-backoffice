<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

interface KeyManagementServiceInterface
{
    /**
     * Generate a new mnemonic phrase.
     */
    public function generateMnemonic(): string;

    /**
     * Derive a key pair from a mnemonic and path.
     */
    public function deriveKeyPair(string $mnemonic, string $path): array;

    /**
     * Derive key pair for a specific blockchain chain.
     */
    public function deriveKeyPairForChain(string $encryptedSeed, string $chain, int $index = 0): array;

    /**
     * Encrypt sensitive data.
     */
    public function encrypt(string $data): string;

    /**
     * Decrypt encrypted data.
     */
    public function decrypt(string $encryptedData): string;

    /**
     * Generate a secure random key.
     */
    public function generateKey(): string;

    /**
     * Sign data with a private key.
     */
    public function sign(string $data, string $privateKey): string;

    /**
     * Verify a signature.
     */
    public function verify(string $data, string $signature, string $publicKey): bool;

    /**
     * Generate wallet backup.
     */
    public function generateBackup(string $walletId): array;
}
