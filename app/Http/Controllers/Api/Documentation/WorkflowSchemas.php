<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'WorkflowExecution',
    required: ['id', 'workflow_name', 'status', 'started_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '123e4567-e89b-12d3-a456-426614174000'),
        new OA\Property(property: 'workflow_name', type: 'string', example: 'TransferWorkflow'),
        new OA\Property(property: 'status', type: 'string', enum: ['running', 'completed', 'failed', 'compensating', 'compensated'], example: 'completed'),
        new OA\Property(property: 'input_data', type: 'object', description: 'Workflow input parameters'),
        new OA\Property(property: 'output_data', type: 'object', description: 'Workflow output data', nullable: true),
        new OA\Property(property: 'error', type: 'string', description: 'Error message if failed', nullable: true),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'duration_ms', type: 'integer', example: 250),
        new OA\Property(
            property: 'steps',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'ValidateTransfer'),
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'running', 'completed', 'failed', 'compensated']),
                    new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'error', type: 'string', nullable: true),
                ],
            ),
        ),
    ],
)]
#[OA\Schema(
    schema: 'WorkflowStatistics',
    required: ['workflow_name', 'period', 'statistics'],
    properties: [
        new OA\Property(property: 'workflow_name', type: 'string', example: 'TransferWorkflow'),
        new OA\Property(property: 'period', type: 'string', example: 'last_24_hours'),
        new OA\Property(
            property: 'statistics',
            type: 'object',
            properties: [
                new OA\Property(property: 'total_executions', type: 'integer', example: 1250),
                new OA\Property(property: 'successful', type: 'integer', example: 1200),
                new OA\Property(property: 'failed', type: 'integer', example: 45),
                new OA\Property(property: 'compensated', type: 'integer', example: 5),
                new OA\Property(property: 'average_duration_ms', type: 'number', example: 185.5),
                new OA\Property(property: 'min_duration_ms', type: 'integer', example: 50),
                new OA\Property(property: 'max_duration_ms', type: 'integer', example: 2500),
                new OA\Property(property: 'success_rate', type: 'number', example: 0.96),
            ],
        ),
        new OA\Property(
            property: 'failure_reasons',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Insufficient balance'),
                    new OA\Property(property: 'count', type: 'integer', example: 30),
                    new OA\Property(property: 'percentage', type: 'number', example: 0.667),
                ],
            ),
        ),
    ],
)]
#[OA\Schema(
    schema: 'CircuitBreakerStatus',
    required: ['service', 'state', 'failure_count', 'last_checked'],
    properties: [
        new OA\Property(property: 'service', type: 'string', example: 'paysera_connector'),
        new OA\Property(property: 'state', type: 'string', enum: ['closed', 'open', 'half_open'], example: 'closed'),
        new OA\Property(property: 'failure_count', type: 'integer', example: 0),
        new OA\Property(property: 'success_count', type: 'integer', example: 150),
        new OA\Property(property: 'threshold', type: 'integer', description: 'Failures before opening', example: 5),
        new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds before half-open', example: 60),
        new OA\Property(property: 'last_failure', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_success', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_checked', type: 'string', format: 'date-time'),
        new OA\Property(property: 'next_retry', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(
            property: 'metadata',
            type: 'object',
            properties: [
                new OA\Property(property: 'error_rate', type: 'number', example: 0.01),
                new OA\Property(property: 'average_response_time_ms', type: 'number', example: 120.5),
            ],
        ),
    ],
)]
#[OA\Schema(
    schema: 'EventReplayRequest',
    required: ['aggregate_uuid', 'from_version'],
    properties: [
        new OA\Property(property: 'aggregate_uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'from_version', type: 'integer', description: 'Starting version to replay from', example: 1),
        new OA\Property(property: 'to_version', type: 'integer', description: 'Ending version to replay to', nullable: true, example: 100),
        new OA\Property(property: 'event_types', type: 'array', description: 'Filter by specific event types', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'dry_run', type: 'boolean', description: 'Simulate replay without applying changes', example: false),
    ],
)]
#[OA\Schema(
    schema: 'EventReplayResult',
    required: ['aggregate_uuid', 'events_replayed', 'status'],
    properties: [
        new OA\Property(property: 'aggregate_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'events_replayed', type: 'integer', example: 50),
        new OA\Property(property: 'status', type: 'string', enum: ['completed', 'failed', 'partial'], example: 'completed'),
        new OA\Property(property: 'final_state', type: 'object', description: 'Final aggregate state after replay'),
        new OA\Property(property: 'errors', type: 'array', description: 'Any errors encountered', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'duration_ms', type: 'integer', example: 450),
        new OA\Property(property: 'dry_run', type: 'boolean', example: false),
    ],
)]
#[OA\Schema(
    schema: 'QueueMetrics',
    required: ['queue_name', 'metrics'],
    properties: [
        new OA\Property(property: 'queue_name', type: 'string', example: 'transactions'),
        new OA\Property(
            property: 'metrics',
            type: 'object',
            properties: [
                new OA\Property(property: 'size', type: 'integer', description: 'Current queue size', example: 125),
                new OA\Property(property: 'processing_rate', type: 'number', description: 'Jobs per second', example: 15.5),
                new OA\Property(property: 'average_wait_time_ms', type: 'number', example: 850),
                new OA\Property(property: 'failed_jobs_24h', type: 'integer', example: 12),
                new OA\Property(property: 'workers', type: 'integer', description: 'Active workers', example: 4),
                new OA\Property(property: 'memory_usage_mb', type: 'number', example: 256.5),
            ],
        ),
        new OA\Property(
            property: 'job_types',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'type', type: 'string', example: 'ProcessTransferJob'),
                    new OA\Property(property: 'count', type: 'integer', example: 45),
                    new OA\Property(property: 'average_duration_ms', type: 'number', example: 120),
                ],
            ),
        ),
    ],
)]
class WorkflowSchemas
{
    // This class only contains OpenAPI schema definitions
}
