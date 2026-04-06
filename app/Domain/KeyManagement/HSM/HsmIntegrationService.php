<?php

declare(strict_types=1);

namespace App\Domain\KeyManagement\HSM;

use App\Domain\KeyManagement\Contracts\HsmProviderInterface;
use RuntimeException;

class HsmIntegrationService
{
    private ?HsmProviderInterface $provider = null;

    public function __construct(?HsmProviderInterface $provider = null)
    {
        $this->provider = $provider;
    }

    public function encrypt(string $data): string
    {
        $this->ensureAvailable();

        return $this->getProvider()->encrypt($data, $this->getKeyId());
    }

    public function decrypt(string $encryptedData): string
    {
        $this->ensureAvailable();

        return $this->getProvider()->decrypt($encryptedData, $this->getKeyId());
    }

    public function store(string $secretId, string $data): bool
    {
        $this->ensureAvailable();

        return $this->getProvider()->store($secretId, $data);
    }

    public function retrieve(string $secretId): ?string
    {
        $this->ensureAvailable();

        return $this->getProvider()->retrieve($secretId);
    }

    public function delete(string $secretId): bool
    {
        $this->ensureAvailable();

        return $this->getProvider()->delete($secretId);
    }

    public function isAvailable(): bool
    {
        return $this->getProvider()->isAvailable();
    }

    public function getProviderName(): string
    {
        return $this->getProvider()->getProviderName();
    }

    /**
     * Sign a message hash using ECDSA with secp256k1.
     *
     * @param  string  $messageHash  32-byte hash to sign (hex with 0x prefix)
     * @param  string|null  $keyId  Optional key ID (defaults to configured key)
     * @return string ECDSA signature in compact format (hex with 0x prefix)
     */
    public function sign(string $messageHash, ?string $keyId = null): string
    {
        $this->ensureAvailable();

        return $this->getProvider()->sign($messageHash, $keyId ?? $this->getSigningKeyId());
    }

    /**
     * Verify an ECDSA signature.
     *
     * @param  string  $messageHash  32-byte hash that was signed (hex with 0x prefix)
     * @param  string  $signature  ECDSA signature (hex with 0x prefix)
     * @param  string  $publicKey  Public key to verify against (hex with 0x prefix)
     * @return bool True if signature is valid
     */
    public function verify(string $messageHash, string $signature, string $publicKey): bool
    {
        $this->ensureAvailable();

        return $this->getProvider()->verify($messageHash, $signature, $publicKey);
    }

    /**
     * Get the public key for signing operations.
     *
     * @param  string|null  $keyId  Optional key ID (defaults to configured key)
     * @return string Public key (hex with 0x prefix)
     */
    public function getPublicKey(?string $keyId = null): string
    {
        $this->ensureAvailable();

        return $this->getProvider()->getPublicKey($keyId ?? $this->getSigningKeyId());
    }

    private function resolveProvider(): HsmProviderInterface
    {
        $providerType = config('keymanagement.hsm.provider', 'demo');

        return (new HsmProviderFactory())->create((string) $providerType);
    }

    private function getProvider(): HsmProviderInterface
    {
        if ($this->provider === null) {
            $this->provider = $this->resolveProvider();
        }

        return $this->provider;
    }

    private function getKeyId(): string
    {
        return config('keymanagement.hsm.key_id', 'default');
    }

    private function getSigningKeyId(): string
    {
        return config('keymanagement.hsm.signing_key_id', 'signing-default');
    }

    private function ensureAvailable(): void
    {
        if (! $this->getProvider()->isAvailable()) {
            throw new RuntimeException(
                "HSM provider '{$this->getProvider()->getProviderName()}' is not available"
            );
        }
    }
}
