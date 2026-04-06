<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Account',
    title: 'Account',
    required: ['uuid', 'user_uuid', 'name', 'balance', 'frozen'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid', example: '660e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'name', type: 'string', example: 'Savings Account'),
        new OA\Property(property: 'balance', type: 'integer', description: 'Balance in cents', example: 50000),
        new OA\Property(property: 'frozen', type: 'boolean', description: 'Whether the account is frozen', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
    ],
)]
#[OA\Schema(
    schema: 'Transaction',
    title: 'Transaction',
    required: ['uuid', 'account_uuid', 'type', 'amount', 'balance_after', 'description', 'hash'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', example: '770e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'type', type: 'string', enum: ['deposit', 'withdrawal'], example: 'deposit'),
        new OA\Property(property: 'amount', type: 'integer', description: 'Amount in cents', example: 10000),
        new OA\Property(property: 'balance_after', type: 'integer', description: 'Balance after transaction in cents', example: 60000),
        new OA\Property(property: 'description', type: 'string', example: 'Monthly salary deposit'),
        new OA\Property(property: 'hash', type: 'string', description: 'SHA3-512 transaction hash', example: '3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
    ],
)]
#[OA\Schema(
    schema: 'Transfer',
    title: 'Transfer',
    required: ['uuid', 'from_account_uuid', 'to_account_uuid', 'amount', 'description', 'status', 'hash'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', example: '880e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'from_account_uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'to_account_uuid', type: 'string', format: 'uuid', example: '660e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'amount', type: 'integer', description: 'Amount in cents', example: 5000),
        new OA\Property(property: 'description', type: 'string', example: 'Payment for services'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'completed', 'failed'], example: 'completed'),
        new OA\Property(property: 'hash', type: 'string', description: 'SHA3-512 transfer hash', example: '4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true, example: '2024-01-01T00:00:01Z'),
    ],
)]
#[OA\Schema(
    schema: 'Balance',
    title: 'Balance',
    required: ['account_uuid', 'balance', 'frozen'],
    properties: [
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'balance', type: 'integer', description: 'Current balance in cents', example: 50000),
        new OA\Property(property: 'frozen', type: 'boolean', example: false),
        new OA\Property(property: 'last_updated', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(
            property: 'turnover',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'total_debit', type: 'integer', example: 100000),
                new OA\Property(property: 'total_credit', type: 'integer', example: 150000),
                new OA\Property(property: 'month', type: 'integer', example: 1),
                new OA\Property(property: 'year', type: 'integer', example: 2024),
            ],
        ),
    ],
)]
#[OA\Schema(
    schema: 'Asset',
    title: 'Asset',
    required: ['code', 'name', 'type', 'precision', 'is_active'],
    properties: [
        new OA\Property(property: 'code', type: 'string', description: 'Asset code (e.g., USD, EUR, BTC)', example: 'USD'),
        new OA\Property(property: 'name', type: 'string', example: 'US Dollar'),
        new OA\Property(property: 'type', type: 'string', enum: ['fiat', 'crypto', 'commodity', 'custom'], example: 'fiat'),
        new OA\Property(property: 'precision', type: 'integer', description: 'Number of decimal places', example: 2),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'metadata', type: 'object', description: 'Additional asset metadata', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
    ],
)]
#[OA\Schema(
    schema: 'ExchangeRate',
    title: 'Exchange Rate',
    required: ['from_asset_code', 'to_asset_code', 'rate', 'is_active'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'from_asset_code', type: 'string', example: 'USD'),
        new OA\Property(property: 'to_asset_code', type: 'string', example: 'EUR'),
        new OA\Property(property: 'rate', type: 'string', description: 'Exchange rate with 10 decimal precision', example: '0.8500000000'),
        new OA\Property(property: 'bid', type: 'string', nullable: true, example: '0.8495000000'),
        new OA\Property(property: 'ask', type: 'string', nullable: true, example: '0.8505000000'),
        new OA\Property(property: 'source', type: 'string', description: 'Rate source', example: 'manual'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'valid_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'metadata', type: 'object', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
    ],
)]
#[OA\Schema(
    schema: 'AccountBalance',
    title: 'Account Balance',
    required: ['account_uuid', 'asset_code', 'balance'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'asset_code', type: 'string', example: 'USD'),
        new OA\Property(property: 'balance', type: 'integer', description: 'Balance in smallest unit (cents for USD)', example: 50000),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'asset', ref: '#/components/schemas/Asset'),
        new OA\Property(property: 'account', ref: '#/components/schemas/Account'),
    ],
)]
#[OA\Schema(
    schema: 'Poll',
    title: 'Poll',
    required: ['id', 'title', 'type', 'status', 'options', 'start_date', 'end_date'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'title', type: 'string', example: 'Should we add support for Japanese Yen?'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'This poll determines whether to add JPY support to the platform'),
        new OA\Property(property: 'type', type: 'string', enum: ['single_choice', 'multiple_choice', 'weighted_choice', 'yes_no', 'ranked_choice'], example: 'yes_no'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'completed', 'cancelled'], example: 'active'),
        new OA\Property(
            property: 'options',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', example: 'yes'),
                    new OA\Property(property: 'label', type: 'string', example: 'Yes, add JPY support'),
                ],
            ),
        ),
        new OA\Property(property: 'voting_power_strategy', type: 'string', example: 'OneUserOneVoteStrategy'),
        new OA\Property(property: 'execution_workflow', type: 'string', nullable: true, example: 'AddAssetWorkflow'),
        new OA\Property(property: 'min_participation', type: 'integer', nullable: true, example: 100),
        new OA\Property(property: 'winning_threshold', type: 'number', format: 'float', nullable: true, example: 0.5),
        new OA\Property(property: 'start_date', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date-time', example: '2024-01-08T00:00:00Z'),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', example: '660e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'votes_count', type: 'integer', description: 'Total number of votes', example: 150),
        new OA\Property(property: 'total_voting_power', type: 'integer', description: 'Total voting power cast', example: 500),
    ],
)]
#[OA\Schema(
    schema: 'Vote',
    title: 'Vote',
    required: ['id', 'poll_id', 'user_uuid', 'selected_options', 'voting_power', 'voted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'poll_id', type: 'integer', example: 1),
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid', example: '660e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'selected_options', type: 'array', items: new OA\Items(type: 'string'), example: ['yes']),
        new OA\Property(property: 'voting_power', type: 'integer', example: 10),
        new OA\Property(property: 'signature', type: 'string', nullable: true, example: 'abc123def456'),
        new OA\Property(property: 'voted_at', type: 'string', format: 'date-time', example: '2024-01-01T12:00:00Z'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-01-01T12:00:00Z'),
        new OA\Property(property: 'poll', ref: '#/components/schemas/Poll'),
    ],
)]
#[OA\Schema(
    schema: 'PollResult',
    title: 'Poll Result',
    required: ['poll', 'results', 'participation'],
    properties: [
        new OA\Property(property: 'poll', ref: '#/components/schemas/Poll'),
        new OA\Property(
            property: 'results',
            type: 'object',
            description: 'Vote results by option',
            example: [
                'yes' => ['votes' => 75, 'voting_power' => 250],
                'no'  => ['votes' => 25, 'voting_power' => 100],
            ],
        ),
        new OA\Property(
            property: 'participation',
            type: 'object',
            properties: [
                new OA\Property(property: 'total_votes', type: 'integer', example: 100),
                new OA\Property(property: 'total_voting_power', type: 'integer', example: 350),
                new OA\Property(property: 'participation_rate', type: 'number', format: 'float', example: 0.25),
                new OA\Property(property: 'winning_option', type: 'string', nullable: true, example: 'yes'),
                new OA\Property(property: 'meets_threshold', type: 'boolean', example: true),
            ],
        ),
        new OA\Property(property: 'calculated_at', type: 'string', format: 'date-time', example: '2024-01-01T12:00:00Z'),
    ],
)]
#[OA\Schema(
    schema: 'Error',
    title: 'Error Response',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(property: 'error', type: 'string', nullable: true, example: 'VALIDATION_ERROR'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            nullable: true,
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
        ),
    ],
)]
#[OA\Schema(
    schema: 'ValidationError',
    title: 'Validation Error Response',
    required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
            example: [
                'email'  => ['The email field is required.'],
                'amount' => ['The amount must be greater than 0.'],
            ],
        ),
    ],
)]
#[OA\Schema(
    schema: 'BasketAsset',
    title: 'Basket Asset',
    required: ['code', 'name', 'type', 'components'],
    properties: [
        new OA\Property(property: 'code', type: 'string', example: 'STABLE_BASKET'),
        new OA\Property(property: 'name', type: 'string', example: 'Stable Currency Basket'),
        new OA\Property(property: 'description', type: 'string', example: 'A diversified basket of stable fiat currencies'),
        new OA\Property(property: 'type', type: 'string', enum: ['fixed', 'dynamic'], example: 'fixed'),
        new OA\Property(property: 'rebalance_frequency', type: 'string', enum: ['daily', 'weekly', 'monthly', 'quarterly', 'never'], example: 'never'),
        new OA\Property(property: 'last_rebalanced_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01T00:00:00Z'),
        new OA\Property(property: 'components', type: 'array', items: new OA\Items(ref: '#/components/schemas/BasketComponent')),
        new OA\Property(
            property: 'latest_value',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'value', type: 'number', format: 'float', example: 1.0975),
                new OA\Property(property: 'calculated_at', type: 'string', format: 'date-time'),
            ],
        ),
    ],
)]
#[OA\Schema(
    schema: 'BasketComponent',
    title: 'Basket Component',
    required: ['asset_code', 'weight'],
    properties: [
        new OA\Property(property: 'asset_code', type: 'string', example: 'USD'),
        new OA\Property(property: 'asset_name', type: 'string', example: 'US Dollar'),
        new OA\Property(property: 'weight', type: 'number', format: 'float', description: 'Weight percentage (0-100)', example: 40.0),
        new OA\Property(property: 'min_weight', type: 'number', format: 'float', nullable: true, example: 35.0),
        new OA\Property(property: 'max_weight', type: 'number', format: 'float', nullable: true, example: 45.0),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
    ],
)]
#[OA\Schema(
    schema: 'BasketValue',
    title: 'Basket Value',
    required: ['basket_code', 'value', 'calculated_at'],
    properties: [
        new OA\Property(property: 'basket_code', type: 'string', example: 'STABLE_BASKET'),
        new OA\Property(property: 'value', type: 'number', format: 'float', description: 'Current value in base currency (USD)', example: 1.0975),
        new OA\Property(property: 'calculated_at', type: 'string', format: 'date-time', example: '2024-01-01T12:00:00Z'),
        new OA\Property(
            property: 'component_values',
            type: 'object',
            description: 'Breakdown of component values',
            example: [
                'USD' => ['value' => 1.0, 'weight' => 40.0, 'weighted_value' => 0.4],
                'EUR' => ['value' => 1.1, 'weight' => 35.0, 'weighted_value' => 0.385],
                'GBP' => ['value' => 1.25, 'weight' => 25.0, 'weighted_value' => 0.3125],
            ],
        ),
    ],
)]
#[OA\Schema(
    schema: 'UserVotingPoll',
    title: 'User Voting Poll',
    required: ['uuid', 'title', 'type', 'status', 'options', 'start_date', 'end_date', 'user_context'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', example: '990e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'title', type: 'string', example: 'Monthly GCU Basket Allocation for June 2025'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Vote on the currency allocation for the Global Currency Unit basket'),
        new OA\Property(property: 'type', type: 'string', enum: ['single_choice', 'multiple_choice', 'weighted_choice', 'yes_no', 'ranked_choice'], example: 'weighted_choice'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'closed', 'cancelled'], example: 'active'),
        new OA\Property(
            property: 'options',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', example: 'USD'),
                    new OA\Property(property: 'label', type: 'string', example: 'US Dollar'),
                ],
            ),
        ),
        new OA\Property(property: 'start_date', type: 'string', format: 'date-time', example: '2025-06-01T00:00:00Z'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date-time', example: '2025-06-08T00:00:00Z'),
        new OA\Property(property: 'required_participation', type: 'number', format: 'float', nullable: true, example: 25.0),
        new OA\Property(property: 'current_participation', type: 'number', format: 'float', example: 15.5),
        new OA\Property(
            property: 'user_context',
            type: 'object',
            properties: [
                new OA\Property(property: 'has_voted', type: 'boolean', example: false),
                new OA\Property(property: 'voting_power', type: 'integer', example: 1000),
                new OA\Property(property: 'can_vote', type: 'boolean', example: true),
                new OA\Property(
                    property: 'vote',
                    type: 'object',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'selected_options', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'voted_at', type: 'string', format: 'date-time'),
                    ],
                ),
            ],
        ),
        new OA\Property(
            property: 'metadata',
            type: 'object',
            properties: [
                new OA\Property(property: 'is_gcu_poll', type: 'boolean', example: true),
                new OA\Property(property: 'voting_month', type: 'string', nullable: true, example: '2025-06'),
                new OA\Property(property: 'template', type: 'string', nullable: true, example: 'monthly_gcu_allocation'),
            ],
        ),
        new OA\Property(property: 'results_visible', type: 'boolean', example: false),
        new OA\Property(
            property: 'time_remaining',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'days', type: 'integer', example: 6),
                new OA\Property(property: 'hours', type: 'integer', example: 12),
                new OA\Property(property: 'human_readable', type: 'string', example: '6 days from now'),
            ],
        ),
    ],
)]
class Schemas
{
}
