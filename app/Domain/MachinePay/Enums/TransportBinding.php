<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Enums;

/**
 * Transport bindings for the Machine Payments Protocol.
 *
 * MPP operates over HTTP (standard 402 flow) and MCP
 * (JSON-RPC with -32042 error code for payment challenges).
 */
enum TransportBinding: string
{
    case HTTP = 'http';
    case MCP = 'mcp';

    public function label(): string
    {
        return match ($this) {
            self::HTTP => 'HTTP (WWW-Authenticate)',
            self::MCP  => 'MCP (JSON-RPC -32042)',
        };
    }

    /**
     * The error/status code used for payment-required signals.
     */
    public function paymentRequiredCode(): int
    {
        return match ($this) {
            self::HTTP => 402,
            self::MCP  => -32042,
        };
    }
}
