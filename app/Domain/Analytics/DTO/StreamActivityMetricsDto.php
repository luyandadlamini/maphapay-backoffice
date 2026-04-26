<?php

declare(strict_types=1);

namespace App\Domain\Analytics\DTO;

/**
 * Per-stream wallet activity from {@see TransactionProjection} (v1 heuristics), not recognized revenue.
 */
final readonly class StreamActivityMetricsDto
{
    /**
     * @param  array<string, int>|null  $volumesByAsset  asset_code => sum of minor units
     */
    public function __construct(
        public string $status,
        public ?int $transactionCount = null,
        public ?array $volumesByAsset = null,
        public ?string $lastActivityAtIso = null,
        public ?string $mappingNote = null,
    ) {
    }

    public static function pending(): self
    {
        return new self('pending');
    }

    /**
     * @param  array<string, int>  $volumesByAsset
     */
    public static function mapped(
        int $transactionCount,
        array $volumesByAsset,
        ?string $lastActivityAtIso,
        string $mappingNote,
    ): self {
        return new self(
            'mapped',
            $transactionCount,
            $volumesByAsset,
            $lastActivityAtIso,
            $mappingNote,
        );
    }

    public function isMapped(): bool
    {
        return $this->status === 'mapped';
    }
}
