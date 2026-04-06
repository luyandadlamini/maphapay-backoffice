<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Contracts\KeyManagementServiceInterface;
use App\Domain\Wallet\Events\KeyAccessed;
use App\Domain\Wallet\Events\KeyStored;
use App\Domain\Wallet\Exceptions\KeyManagementException;
use App\Domain\Wallet\Models\KeyAccessLog;
use App\Domain\Wallet\Models\SecureKeyStorage;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Secure key storage service with enhanced security features.
 */
class SecureKeyStorageService
{
    protected Encrypter $encrypter;

    protected KeyManagementServiceInterface $keyManager;

    /**
     * Key derivation iterations for PBKDF2.
     */
    private const KEY_DERIVATION_ITERATIONS = 100000;

    /**
     * Key version for rotation tracking.
     */
    private const CURRENT_KEY_VERSION = 1;

    /**
     * Constructor.
     */
    public function __construct(
        Encrypter $encrypter,
        KeyManagementServiceInterface $keyManager
    ) {
        $this->encrypter = $encrypter;
        $this->keyManager = $keyManager;
    }

    /**
     * Store encrypted seed securely with audit logging.
     */
    public function storeEncryptedSeed(
        string $walletId,
        string $seed,
        string $userId,
        array $metadata = []
    ): void {
        DB::transaction(function () use ($walletId, $seed, $userId, $metadata) {
            // Generate unique salt for this wallet
            $salt = bin2hex(random_bytes(32));

            // Derive encryption key using PBKDF2
            $derivedKey = $this->deriveKey($walletId, $salt);

            // Generate random IV
            $iv = random_bytes(16);

            // Encrypt the seed with AES-256-GCM
            $encrypted = $this->encryptWithAesGcm($seed, $derivedKey, $iv);

            // Store encrypted data
            SecureKeyStorage::create([
                'wallet_id'      => $walletId,
                'encrypted_data' => base64_encode($encrypted['ciphertext']),
                'auth_tag'       => base64_encode($encrypted['tag']),
                'iv'             => base64_encode($iv),
                'salt'           => $salt,
                'key_version'    => self::CURRENT_KEY_VERSION,
                'storage_type'   => 'database',
                'is_active'      => true,
                'metadata'       => array_merge($metadata, [
                    'algorithm'  => 'AES-256-GCM',
                    'created_by' => $userId,
                    'created_at' => now()->toIso8601String(),
                ]),
            ]);

            // Log the storage event
            $this->logKeyAccess($walletId, $userId, 'store', [
                'key_version'  => self::CURRENT_KEY_VERSION,
                'storage_type' => 'database',
            ]);

            // Dispatch event
            Event::dispatch(new KeyStored($walletId, $userId));
        });
    }

    /**
     * Retrieve and decrypt seed with audit logging.
     */
    public function retrieveEncryptedSeed(
        string $walletId,
        string $userId,
        ?string $purpose = null
    ): string {
        $storage = SecureKeyStorage::where('wallet_id', $walletId)
            ->where('is_active', true)
            ->first();

        if (! $storage) {
            throw new KeyManagementException("Seed not found for wallet: {$walletId}");
        }

        // Log access attempt
        $this->logKeyAccess($walletId, $userId, 'retrieve', [
            'purpose'     => $purpose,
            'key_version' => $storage->key_version,
        ]);

        // Derive the same key using the stored salt
        $derivedKey = $this->deriveKey($walletId, $storage->salt);

        // Decrypt the seed
        $decrypted = $this->decryptWithAesGcm(
            base64_decode($storage->encrypted_data),
            $derivedKey,
            base64_decode($storage->iv),
            base64_decode($storage->auth_tag)
        );

        // Dispatch event
        Event::dispatch(new KeyAccessed($walletId, $userId, $purpose));

        return $decrypted;
    }

    /**
     * Store private key temporarily with TTL and access control.
     */
    public function storeTemporaryKey(
        string $userId,
        string $privateKey,
        int $ttl = 300,
        array $permissions = []
    ): string {
        $token = Str::random(64);
        $cacheKey = "secure_key:{$userId}:{$token}";

        // Encrypt the private key
        $encrypted = $this->encrypter->encrypt([
            'key'         => $privateKey,
            'permissions' => $permissions,
            'created_at'  => now()->timestamp,
            'expires_at'  => now()->addSeconds($ttl)->timestamp,
        ]);

        // Store with TTL
        Cache::put($cacheKey, $encrypted, $ttl);

        // Log temporary storage
        $this->logKeyAccess('temporary', $userId, 'temp_store', [
            'ttl'         => $ttl,
            'permissions' => $permissions,
        ]);

        return $token;
    }

    /**
     * Retrieve temporary key with permission validation.
     */
    public function retrieveTemporaryKey(
        string $userId,
        string $token,
        ?string $requiredPermission = null
    ): ?string {
        $cacheKey = "secure_key:{$userId}:{$token}";
        $encrypted = Cache::get($cacheKey);

        if (! $encrypted) {
            return null;
        }

        $data = $this->encrypter->decrypt($encrypted);

        // Validate expiration
        if ($data['expires_at'] < now()->timestamp) {
            Cache::forget($cacheKey);

            return null;
        }

        // Validate permissions
        if ($requiredPermission && ! in_array($requiredPermission, $data['permissions'])) {
            throw new KeyManagementException('Insufficient permissions for key access');
        }

        // Remove from cache after retrieval (one-time use)
        Cache::forget($cacheKey);

        // Log access
        $this->logKeyAccess('temporary', $userId, 'temp_retrieve', [
            'permission_used' => $requiredPermission,
        ]);

        return $data['key'];
    }

    /**
     * Rotate encryption keys for a wallet.
     */
    public function rotateKeys(
        string $walletId,
        string $userId,
        ?string $reason = null
    ): void {
        DB::transaction(function () use ($walletId, $userId, $reason) {
            $storage = SecureKeyStorage::where('wallet_id', $walletId)
                ->where('is_active', true)
                ->firstOrFail();

            // Retrieve current seed
            $seed = $this->retrieveEncryptedSeed($walletId, $userId, 'rotation');

            // Mark old storage as inactive
            $storage->update(['is_active' => false]);

            // Store with new encryption parameters
            $this->storeEncryptedSeed($walletId, $seed, $userId, [
                'rotation_reason'  => $reason,
                'previous_version' => $storage->key_version,
                'rotated_at'       => now()->toIso8601String(),
            ]);

            // Log rotation
            $this->logKeyAccess($walletId, $userId, 'rotate', [
                'reason'      => $reason,
                'old_version' => $storage->key_version,
                'new_version' => self::CURRENT_KEY_VERSION,
            ]);

            // For now, we'll skip the event dispatch as it requires more parameters
            // In production, this would dispatch a proper event with all required data
            Log::info('Wallet keys rotated', [
                'wallet_id' => $walletId,
                'user_id'   => $userId,
                'reason'    => $reason,
            ]);
        });
    }

    /**
     * Store key in Hardware Security Module (HSM).
     */
    public function storeInHSM(string $walletId, string $encryptedSeed): void
    {
        // In production, this would integrate with actual HSM
        // For now, we'll use enhanced database storage
        $this->storeEncryptedSeed($walletId, $encryptedSeed, 'system', [
            'storage_type'  => 'hsm_simulated',
            'hsm_partition' => config('blockchain.hsm.partition', 'default'),
        ]);

        Log::info('HSM storage simulated for wallet', [
            'wallet_id' => $walletId,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Derive encryption key using PBKDF2.
     */
    protected function deriveKey(string $walletId, string $salt): string
    {
        $password = $walletId . config('app.key');

        return hash_pbkdf2(
            'sha256',
            $password,
            $salt,
            self::KEY_DERIVATION_ITERATIONS,
            32,
            true
        );
    }

    /**
     * Encrypt data using AES-256-GCM.
     */
    protected function encryptWithAesGcm(string $data, string $key, string $iv): array
    {
        $tag = '';
        $ciphertext = openssl_encrypt(
            $data,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new KeyManagementException('Encryption failed');
        }

        return [
            'ciphertext' => $ciphertext,
            'tag'        => $tag,
        ];
    }

    /**
     * Decrypt data using AES-256-GCM.
     */
    protected function decryptWithAesGcm(
        string $ciphertext,
        string $key,
        string $iv,
        string $tag
    ): string {
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new KeyManagementException('Decryption failed - data may be tampered');
        }

        return $plaintext;
    }

    /**
     * Log key access for audit trail.
     */
    protected function logKeyAccess(
        string $walletId,
        string $userId,
        string $action,
        array $metadata = []
    ): void {
        KeyAccessLog::create([
            'wallet_id'   => $walletId,
            'user_id'     => $userId,
            'action'      => $action,
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
            'metadata'    => $metadata,
            'accessed_at' => now(),
        ]);
    }

    /**
     * Purge expired temporary keys.
     */
    public function purgeExpiredKeys(): int
    {
        // In production, this would be handled by Redis TTL
        // For now, we'll use a simple implementation
        $purged = 0;

        // Get all key access logs for temporary keys that are older than TTL
        $expiredLogs = KeyAccessLog::where('action', 'temp_store')
            ->where('accessed_at', '<', now()->subSeconds(config('blockchain.key_access.temp_key_ttl', 300)))
            ->get();

        foreach ($expiredLogs as $log) {
            if (isset($log->metadata['cache_key'])) {
                Cache::forget($log->metadata['cache_key']);
                $purged++;
            }
        }

        Log::info('Purged expired temporary keys', ['count' => $purged]);

        return $purged;
    }
}
