<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AIMessage',
    title: 'AI Message',
    description: 'AI conversation message',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', description: 'The message content', example: 'What is my account balance?'),
        new OA\Property(property: 'conversation_id', type: 'string', format: 'uuid', description: 'Conversation ID for context', example: 'conv_123e4567-e89b-12d3-a456'),
        new OA\Property(property: 'context', type: 'object', description: 'Additional context for the AI', example: ['account_id' => 'acct_123', 'session_type' => 'web']),
        new OA\Property(property: 'model', type: 'string', enum: ['gpt-4', 'gpt-3.5-turbo', 'claude-3', 'llama-2'], description: 'AI model to use', example: 'gpt-4'),
        new OA\Property(property: 'temperature', type: 'number', minimum: 0, maximum: 2, description: 'Creativity level', example: 0.7),
        new OA\Property(property: 'stream', type: 'boolean', description: 'Enable streaming responses', example: false),
        new OA\Property(property: 'enable_tools', type: 'boolean', description: 'Allow AI to use MCP tools', example: true),
        new OA\Property(property: 'max_tokens', type: 'integer', description: 'Maximum response length', example: 500),
    ],
)]
#[OA\Schema(
    schema: 'AIResponse',
    title: 'AI Response',
    description: 'AI agent response',
    properties: [
        new OA\Property(property: 'message_id', type: 'string', description: 'Unique message ID', example: 'msg_abc123xyz'),
        new OA\Property(property: 'conversation_id', type: 'string', description: 'Conversation ID', example: 'conv_123e4567'),
        new OA\Property(property: 'content', type: 'string', description: 'AI response content', example: 'Your current balance is $12,456.78'),
        new OA\Property(property: 'confidence', type: 'number', minimum: 0, maximum: 1, description: 'Confidence score', example: 0.92),
        new OA\Property(property: 'tools_used', type: 'array', items: new OA\Items(type: 'string'), description: 'MCP tools used', example: ['AccountBalanceTool', 'TransactionHistoryTool']),
        new OA\Property(property: 'requires_action', type: 'boolean', description: 'Whether user action is required', example: false),
        new OA\Property(
            property: 'actions',
            type: 'array',
            description: 'Required actions',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'type', type: 'string', example: 'transfer'),
                    new OA\Property(property: 'description', type: 'string', example: 'Transfer $500 to John Smith'),
                    new OA\Property(property: 'parameters', type: 'object'),
                    new OA\Property(property: 'confidence', type: 'number', example: 0.89),
                ],
            ),
        ),
        new OA\Property(property: 'metadata', type: 'object', description: 'Response metadata'),
    ],
)]
#[OA\Schema(
    schema: 'MCPTool',
    title: 'MCP Tool',
    description: 'Model Context Protocol tool definition',
    properties: [
        new OA\Property(property: 'name', type: 'string', description: 'Tool name', example: 'get_account_balance'),
        new OA\Property(property: 'description', type: 'string', description: 'Tool description', example: 'Retrieve account balance'),
        new OA\Property(property: 'category', type: 'string', description: 'Tool category', example: 'account_management'),
        new OA\Property(property: 'parameters', type: 'object', description: 'Tool parameters schema'),
        new OA\Property(property: 'requires_auth', type: 'boolean', description: 'Authentication required', example: true),
        new OA\Property(property: 'requires_2fa', type: 'boolean', description: '2FA required', example: false),
        new OA\Property(property: 'rate_limit', type: 'integer', description: 'Rate limit per minute', example: 100),
        new OA\Property(property: 'cache_ttl', type: 'integer', description: 'Cache TTL in seconds', example: 60),
        new OA\Property(property: 'ml_enabled', type: 'boolean', description: 'ML features enabled', example: false),
        new OA\Property(property: 'real_time', type: 'boolean', description: 'Real-time processing', example: true),
    ],
)]
#[OA\Schema(
    schema: 'MCPToolExecution',
    title: 'MCP Tool Execution',
    description: 'MCP tool execution request',
    required: ['parameters'],
    properties: [
        new OA\Property(property: 'parameters', type: 'object', description: 'Tool-specific parameters', example: ['account_id' => 'acct_123', 'include_pending' => true]),
        new OA\Property(property: 'timeout', type: 'integer', description: 'Execution timeout in ms', example: 5000),
        new OA\Property(property: 'async', type: 'boolean', description: 'Execute asynchronously', example: false),
    ],
)]
#[OA\Schema(
    schema: 'MCPToolResult',
    title: 'MCP Tool Result',
    description: 'MCP tool execution result',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', description: 'Execution success', example: true),
        new OA\Property(property: 'tool', type: 'string', description: 'Tool name', example: 'get_account_balance'),
        new OA\Property(property: 'result', type: 'object', description: 'Tool execution result'),
        new OA\Property(property: 'execution_time_ms', type: 'integer', description: 'Execution time', example: 145),
        new OA\Property(property: 'error', type: 'string', description: 'Error message if failed'),
        new OA\Property(property: 'metadata', type: 'object', description: 'Execution metadata'),
    ],
)]
#[OA\Schema(
    schema: 'AIConversation',
    title: 'AI Conversation',
    description: 'AI conversation thread',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Conversation ID', example: 'conv_123e4567'),
        new OA\Property(property: 'title', type: 'string', description: 'Conversation title', example: 'Account Balance Inquiry'),
        new OA\Property(property: 'user_id', type: 'integer', description: 'User ID', example: 123),
        new OA\Property(
            property: 'messages',
            type: 'array',
            description: 'Conversation messages',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'role', type: 'string', enum: ['user', 'assistant', 'system'], example: 'user'),
                    new OA\Property(property: 'content', type: 'string', example: 'What is my balance?'),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ],
            ),
        ),
        new OA\Property(property: 'context', type: 'object', description: 'Conversation context'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'AIFeedback',
    title: 'AI Feedback',
    description: 'User feedback for AI response',
    required: ['message_id', 'rating'],
    properties: [
        new OA\Property(property: 'message_id', type: 'string', description: 'Message ID to provide feedback for', example: 'msg_abc123'),
        new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5, description: 'Rating 1-5', example: 5),
        new OA\Property(property: 'feedback', type: 'string', description: 'Optional text feedback', example: 'Very helpful response'),
        new OA\Property(property: 'issues', type: 'array', items: new OA\Items(type: 'string'), description: 'Reported issues', example: ['incorrect_info', 'too_verbose']),
    ],
)]
#[OA\Schema(
    schema: 'MCPToolRegistration',
    title: 'MCP Tool Registration',
    description: 'Register a new MCP tool',
    required: ['name', 'description', 'endpoint', 'parameters'],
    properties: [
        new OA\Property(property: 'name', type: 'string', description: 'Tool name', example: 'custom_analysis_tool'),
        new OA\Property(property: 'description', type: 'string', description: 'Tool description', example: 'Custom financial analysis tool'),
        new OA\Property(property: 'endpoint', type: 'string', format: 'url', description: 'Tool endpoint URL', example: 'https://api.example.com/tool'),
        new OA\Property(property: 'parameters', type: 'object', description: 'Parameter schema'),
        new OA\Property(property: 'category', type: 'string', description: 'Tool category', example: 'analysis'),
        new OA\Property(property: 'authentication', type: 'object', description: 'Authentication configuration'),
        new OA\Property(property: 'rate_limit', type: 'integer', description: 'Rate limit', example: 50),
        new OA\Property(property: 'timeout', type: 'integer', description: 'Timeout in ms', example: 10000),
    ],
)]
#[OA\Schema(
    schema: 'AIWorkflow',
    title: 'AI Workflow',
    description: 'AI-driven workflow definition',
    properties: [
        new OA\Property(property: 'id', type: 'string', description: 'Workflow ID', example: 'wf_789xyz'),
        new OA\Property(property: 'type', type: 'string', description: 'Workflow type', example: 'customer_service'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'running', 'completed', 'failed'], example: 'running'),
        new OA\Property(
            property: 'steps',
            type: 'array',
            description: 'Workflow steps',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'analyze_request'),
                    new OA\Property(property: 'type', type: 'string', example: 'ai_analysis'),
                    new OA\Property(property: 'status', type: 'string', example: 'completed'),
                    new OA\Property(property: 'result', type: 'object'),
                ],
            ),
        ),
        new OA\Property(property: 'ai_config', type: 'object', description: 'AI configuration'),
        new OA\Property(property: 'human_intervention', type: 'object', description: 'Human-in-the-loop config'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
    ],
)]
class AISchemas
{
}
