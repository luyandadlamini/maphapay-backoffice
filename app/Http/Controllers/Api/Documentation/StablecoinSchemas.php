<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Stablecoin',
    required: ['code', 'name', 'symbol', 'pegged_currency', 'pegged_value', 'reserve_requirement', 'is_active'],
    properties: [
        new OA\Property(property: 'code', type: 'string', description: 'Unique stablecoin code', example: 'STABLE_LITAS'),
        new OA\Property(property: 'name', type: 'string', description: 'Stablecoin name', example: 'Stable LITAS'),
        new OA\Property(property: 'symbol', type: 'string', description: 'Trading symbol', example: 'sLITAS'),
        new OA\Property(property: 'peg_asset_code', type: 'string', description: 'Asset the stablecoin is pegged to', example: 'EUR'),
        new OA\Property(property: 'peg_ratio', type: 'string', description: 'Peg ratio', example: '1.00000000'),
        new OA\Property(property: 'target_price', type: 'string', description: 'Target price', example: '1.00000000'),
        new OA\Property(property: 'stability_mechanism', type: 'string', enum: ['collateralized', 'algorithmic', 'hybrid'], example: 'collateralized'),
        new OA\Property(property: 'collateral_ratio', type: 'string', description: 'Required collateral ratio', example: '1.5000'),
        new OA\Property(property: 'min_collateral_ratio', type: 'string', description: 'Minimum collateral ratio before liquidation', example: '1.2000'),
        new OA\Property(property: 'liquidation_penalty', type: 'string', description: 'Liquidation penalty percentage', example: '0.1000'),
        new OA\Property(property: 'total_supply', type: 'integer', description: 'Total supply in smallest unit', example: 1000000),
        new OA\Property(property: 'max_supply', type: 'integer', description: 'Maximum supply limit', example: 10000000),
        new OA\Property(property: 'total_collateral_value', type: 'integer', description: 'Total collateral value', example: 1500000),
        new OA\Property(property: 'mint_fee', type: 'string', description: 'Minting fee percentage', example: '0.005000'),
        new OA\Property(property: 'burn_fee', type: 'string', description: 'Burning fee percentage', example: '0.003000'),
        new OA\Property(property: 'precision', type: 'integer', description: 'Decimal precision', example: 2),
        new OA\Property(property: 'is_active', type: 'boolean', description: 'Whether the stablecoin is active', example: true),
        new OA\Property(property: 'minting_enabled', type: 'boolean', description: 'Whether minting is enabled', example: true),
        new OA\Property(property: 'burning_enabled', type: 'boolean', description: 'Whether burning is enabled', example: true),
        new OA\Property(property: 'metadata', type: 'object', description: 'Additional metadata'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2025-01-15T10:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2025-01-15T10:00:00Z'),
    ],
)]
#[OA\Schema(
    schema: 'CreateStablecoinRequest',
    required: ['code', 'name', 'symbol', 'pegged_currency', 'pegged_value', 'initial_reserve', 'reserve_requirement'],
    properties: [
        new OA\Property(property: 'code', type: 'string', description: 'Unique stablecoin code', example: 'STABLE_LITAS'),
        new OA\Property(property: 'name', type: 'string', description: 'Stablecoin name', example: 'Stable LITAS'),
        new OA\Property(property: 'symbol', type: 'string', description: 'Trading symbol', example: 'sLITAS'),
        new OA\Property(property: 'pegged_currency', type: 'string', description: 'Currency to peg to', example: 'EUR'),
        new OA\Property(property: 'pegged_value', type: 'number', description: 'Pegged value ratio', example: 1.0),
        new OA\Property(property: 'initial_reserve', type: 'integer', description: 'Initial reserve amount', example: 1000000),
        new OA\Property(property: 'reserve_requirement', type: 'number', description: 'Required reserve ratio', example: 1.1),
        new OA\Property(property: 'metadata', type: 'object', description: 'Additional metadata'),
    ],
)]
#[OA\Schema(
    schema: 'MintStablecoinRequest',
    required: ['account_uuid', 'amount'],
    properties: [
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid', description: 'Account to mint tokens to', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'amount', type: 'integer', description: 'Amount to mint in smallest unit', example: 100000),
        new OA\Property(property: 'reference', type: 'string', description: 'Reference for the minting operation', example: 'MINT-2025-001'),
        new OA\Property(property: 'metadata', type: 'object', description: 'Additional metadata for the operation'),
    ],
)]
#[OA\Schema(
    schema: 'BurnStablecoinRequest',
    required: ['account_uuid', 'amount'],
    properties: [
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid', description: 'Account to burn tokens from', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'amount', type: 'integer', description: 'Amount to burn in smallest unit', example: 50000),
        new OA\Property(property: 'reference', type: 'string', description: 'Reference for the burning operation', example: 'BURN-2025-001'),
        new OA\Property(property: 'metadata', type: 'object', description: 'Additional metadata for the operation'),
    ],
)]
#[OA\Schema(
    schema: 'StablecoinOperation',
    required: ['id', 'stablecoin_code', 'type', 'account_uuid', 'amount', 'status', 'reference'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '123e4567-e89b-12d3-a456-426614174000'),
        new OA\Property(property: 'stablecoin_code', type: 'string', example: 'STABLE_LITAS'),
        new OA\Property(property: 'type', type: 'string', enum: ['mint', 'burn', 'transfer'], example: 'mint'),
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'amount', type: 'integer', example: 100000),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'completed', 'failed', 'cancelled'], example: 'completed'),
        new OA\Property(property: 'reference', type: 'string', example: 'MINT-2025-001'),
        new OA\Property(property: 'tx_hash', type: 'string', description: 'Blockchain transaction hash if applicable', example: '0x123...abc'),
        new OA\Property(property: 'metadata', type: 'object'),
        new OA\Property(property: 'executed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'StablecoinReserve',
    required: ['stablecoin_code', 'reserve_amount', 'required_amount', 'reserve_ratio', 'is_compliant'],
    properties: [
        new OA\Property(property: 'stablecoin_code', type: 'string', example: 'STABLE_LITAS'),
        new OA\Property(property: 'reserve_amount', type: 'integer', description: 'Current reserve amount', example: 1100000),
        new OA\Property(property: 'required_amount', type: 'integer', description: 'Required reserve amount', example: 1000000),
        new OA\Property(property: 'reserve_ratio', type: 'number', description: 'Current reserve ratio', example: 1.1),
        new OA\Property(property: 'is_compliant', type: 'boolean', description: 'Whether reserves meet requirements', example: true),
        new OA\Property(property: 'last_audit_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'custodian_balances',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'custodian', type: 'string', example: 'deutsche_bank'),
                    new OA\Property(property: 'amount', type: 'integer', example: 550000),
                ],
            ),
        ),
    ],
)]
#[OA\Schema(
    schema: 'LiquidationCheckResult',
    required: ['can_liquidate', 'liquidation_amount', 'reserve_after', 'ratio_after'],
    properties: [
        new OA\Property(property: 'can_liquidate', type: 'boolean', example: true),
        new OA\Property(property: 'liquidation_amount', type: 'integer', description: 'Maximum amount that can be liquidated', example: 50000),
        new OA\Property(property: 'current_reserve', type: 'integer', example: 1100000),
        new OA\Property(property: 'reserve_after', type: 'integer', description: 'Reserve after liquidation', example: 1050000),
        new OA\Property(property: 'ratio_after', type: 'number', description: 'Reserve ratio after liquidation', example: 1.05),
        new OA\Property(property: 'minimum_required_reserve', type: 'integer', example: 1000000),
    ],
)]
class StablecoinSchemas
{
    // This class only contains OpenAPI schema definitions
}
