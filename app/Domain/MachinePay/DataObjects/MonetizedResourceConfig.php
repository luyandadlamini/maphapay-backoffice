<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\DataObjects;

/**
 * Configuration for an MPP-monetized API endpoint.
 *
 * Associates an HTTP method + path with pricing, available rails,
 * and descriptive metadata for the MPP discovery service.
 */
readonly class MonetizedResourceConfig
{
    /**
     * @param string        $method         HTTP method (GET, POST, etc.).
     * @param string        $path           Route path (e.g. "api/v1/data/premium").
     * @param int           $amountCents    Price in smallest currency unit.
     * @param string        $currency       ISO 4217 currency code.
     * @param array<string> $availableRails Available payment rails.
     * @param string|null   $description    Human-readable description.
     * @param string|null   $mimeType       Response MIME type.
     */
    public function __construct(
        public string $method,
        public string $path,
        public int $amountCents,
        public string $currency,
        public array $availableRails,
        public ?string $description = null,
        public ?string $mimeType = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'method'          => $this->method,
            'path'            => $this->path,
            'amount_cents'    => $this->amountCents,
            'currency'        => $this->currency,
            'available_rails' => $this->availableRails,
            'description'     => $this->description,
            'mime_type'       => $this->mimeType,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            method: (string) ($data['method'] ?? 'GET'),
            path: (string) ($data['path'] ?? ''),
            amountCents: (int) ($data['amount_cents'] ?? 0),
            currency: (string) ($data['currency'] ?? 'USD'),
            availableRails: (array) ($data['available_rails'] ?? []),
            description: isset($data['description']) ? (string) $data['description'] : null,
            mimeType: isset($data['mime_type']) ? (string) $data['mime_type'] : null,
        );
    }
}
