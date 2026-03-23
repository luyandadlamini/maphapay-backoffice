<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services;

use App\Domain\MachinePay\DataObjects\MppChallenge;
use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\DataObjects\MppReceipt;
use App\Domain\MachinePay\Exceptions\MppException;

/**
 * Codec for MPP protocol headers.
 *
 * Handles encoding/decoding of the three core MPP headers:
 * - WWW-Authenticate: Payment (challenge, server→client)
 * - Authorization: Payment (credential, client→server)
 * - Payment-Receipt (receipt, server→client)
 *
 * Uses base64url encoding with JCS (JSON Canonicalization Scheme)
 * serialization per the MPP specification.
 */
class MppHeaderCodecService
{
    private const MAX_HEADER_SIZE = 8192; // 8KB max

    /**
     * Encode a challenge for the WWW-Authenticate: Payment header.
     */
    public function encodeChallenge(MppChallenge $challenge): string
    {
        return 'Payment ' . $challenge->toBase64Url();
    }

    /**
     * Decode a credential from the Authorization: Payment header.
     */
    public function decodeCredential(string $header): MppCredential
    {
        $payload = $this->extractPayload($header, 'Payment');

        if (strlen($payload) > self::MAX_HEADER_SIZE) {
            throw new MppException('Credential header exceeds maximum size of 8KB.');
        }

        return MppCredential::fromBase64Url($payload);
    }

    /**
     * Encode a receipt for the Payment-Receipt header.
     */
    public function encodeReceipt(MppReceipt $receipt): string
    {
        return $receipt->toBase64Url();
    }

    /**
     * Decode a receipt from the Payment-Receipt header.
     */
    public function decodeReceipt(string $header): MppReceipt
    {
        $decoded = $this->base64UrlDecode($header);

        /** @var array<string, mixed>|null $data */
        $data = json_decode($decoded, true);

        if (! is_array($data)) {
            throw new MppException('Payment-Receipt header is not valid JSON.');
        }

        return MppReceipt::fromArray($data);
    }

    /**
     * Extract the base64url payload from a header with scheme prefix.
     */
    private function extractPayload(string $header, string $scheme): string
    {
        $prefix = $scheme . ' ';

        if (str_starts_with($header, $prefix)) {
            return substr($header, strlen($prefix));
        }

        // If no scheme prefix, treat entire header as payload
        return $header;
    }

    /**
     * Base64url decode a string.
     */
    private function base64UrlDecode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new MppException('Failed to base64url-decode header payload.');
        }

        return $decoded;
    }
}
