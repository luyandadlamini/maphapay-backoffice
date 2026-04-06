<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'KycDocument',
    required: ['id', 'user_uuid', 'document_type', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '123e4567-e89b-12d3-a456-426614174000'),
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'document_type', type: 'string', enum: ['passport', 'national_id', 'driving_license', 'proof_of_address', 'bank_statement'], example: 'passport'),
        new OA\Property(property: 'document_number', type: 'string', description: 'Document identification number', example: 'AB123456'),
        new OA\Property(property: 'file_path', type: 'string', example: '/documents/kyc/123e4567.pdf'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected', 'expired'], example: 'approved'),
        new OA\Property(property: 'verification_notes', type: 'string', example: 'Document verified successfully'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date', example: '2030-12-31'),
        new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', example: '2025-01-15T10:00:00Z'),
        new OA\Property(property: 'verified_by', type: 'string', example: 'admin@finaegis.org'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2025-01-15T09:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2025-01-15T10:00:00Z'),
    ],
)]
#[OA\Schema(
    schema: 'KycStatus',
    required: ['user_uuid', 'verification_level', 'status', 'documents', 'next_review_date'],
    properties: [
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'verification_level', type: 'string', description: 'KYC verification tier', enum: ['basic', 'enhanced', 'premium'], example: 'enhanced'),
        new OA\Property(property: 'status', type: 'string', enum: ['unverified', 'pending', 'verified', 'rejected', 'expired'], example: 'verified'),
        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(ref: '#/components/schemas/KycDocument')),
        new OA\Property(
            property: 'limits',
            type: 'object',
            properties: [
                new OA\Property(property: 'daily_limit', type: 'integer', description: 'Daily transaction limit in cents', example: 10000000),
                new OA\Property(property: 'monthly_limit', type: 'integer', description: 'Monthly transaction limit in cents', example: 100000000),
                new OA\Property(property: 'single_transaction_limit', type: 'integer', description: 'Single transaction limit in cents', example: 5000000),
            ],
        ),
        new OA\Property(property: 'risk_score', type: 'integer', description: 'Risk assessment score', minimum: 0, maximum: 100, example: 25),
        new OA\Property(property: 'next_review_date', type: 'string', format: 'date', example: '2026-01-15'),
        new OA\Property(property: 'last_verified_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'UploadKycDocumentRequest',
    required: ['document_type', 'document_file'],
    properties: [
        new OA\Property(property: 'document_type', type: 'string', enum: ['passport', 'national_id', 'driving_license', 'proof_of_address', 'bank_statement']),
        new OA\Property(property: 'document_number', type: 'string', description: 'Document identification number', example: 'AB123456'),
        new OA\Property(property: 'document_file', type: 'string', format: 'binary', description: 'Document file upload'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date', description: 'Document expiration date', example: '2030-12-31'),
        new OA\Property(property: 'metadata', type: 'object', description: 'Additional document metadata'),
    ],
)]
#[OA\Schema(
    schema: 'VerifyKycDocumentRequest',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['approved', 'rejected'], example: 'approved'),
        new OA\Property(property: 'verification_notes', type: 'string', example: 'Document verified against government database'),
        new OA\Property(property: 'risk_factors', type: 'array', items: new OA\Items(type: 'string'), example: ['pep', 'high_risk_country']),
    ],
)]
#[OA\Schema(
    schema: 'GdprDataRequest',
    required: ['id', 'user_uuid', 'request_type', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'request_type', type: 'string', enum: ['export', 'deletion', 'rectification', 'portability'], example: 'export'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'processing', 'completed', 'rejected'], example: 'completed'),
        new OA\Property(property: 'requested_data', type: 'array', items: new OA\Items(type: 'string'), example: ['personal_info', 'transactions', 'documents']),
        new OA\Property(property: 'completion_file', type: 'string', description: 'Path to completed export file', example: '/gdpr/exports/user_data_123.zip'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'notes', type: 'string', example: 'Data export completed successfully'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'CreateGdprRequestRequest',
    required: ['request_type'],
    properties: [
        new OA\Property(property: 'request_type', type: 'string', enum: ['export', 'deletion', 'rectification', 'portability']),
        new OA\Property(property: 'requested_data', type: 'array', description: 'Specific data categories requested', items: new OA\Items(type: 'string'), example: ['personal_info', 'transactions']),
        new OA\Property(property: 'reason', type: 'string', description: 'Reason for the request', example: 'Personal backup'),
        new OA\Property(property: 'target_system', type: 'string', description: 'For portability requests, where to send data', example: 'competitor_bank'),
    ],
)]
#[OA\Schema(
    schema: 'ConsentRecord',
    required: ['id', 'user_uuid', 'consent_type', 'status', 'version', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'consent_type', type: 'string', enum: ['marketing', 'data_processing', 'third_party_sharing', 'cookies'], example: 'marketing'),
        new OA\Property(property: 'status', type: 'string', enum: ['granted', 'revoked', 'expired'], example: 'granted'),
        new OA\Property(property: 'version', type: 'string', description: 'Version of consent terms', example: '1.0'),
        new OA\Property(property: 'ip_address', type: 'string', example: '192.168.1.1'),
        new OA\Property(property: 'user_agent', type: 'string', example: 'Mozilla/5.0...'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'revoked_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'UpdateConsentRequest',
    required: ['consent_type', 'status'],
    properties: [
        new OA\Property(property: 'consent_type', type: 'string', enum: ['marketing', 'data_processing', 'third_party_sharing', 'cookies']),
        new OA\Property(property: 'status', type: 'string', enum: ['granted', 'revoked'], example: 'granted'),
        new OA\Property(property: 'duration_days', type: 'integer', description: 'Consent duration in days', example: 365),
    ],
)]
#[OA\Schema(
    schema: 'AmlAlert',
    required: ['id', 'user_uuid', 'alert_type', 'severity', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'alert_type', type: 'string', enum: ['high_value_transaction', 'rapid_movement', 'suspicious_pattern', 'sanctions_match', 'pep_match'], example: 'high_value_transaction'),
        new OA\Property(property: 'severity', type: 'string', enum: ['low', 'medium', 'high', 'critical'], example: 'high'),
        new OA\Property(property: 'status', type: 'string', enum: ['new', 'under_review', 'escalated', 'closed', 'reported'], example: 'under_review'),
        new OA\Property(property: 'transaction_ids', type: 'array', description: 'Related transaction IDs', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'amount', type: 'integer', description: 'Amount involved in cents', example: 10000000),
        new OA\Property(property: 'description', type: 'string', example: 'Multiple high-value transactions within 24 hours'),
        new OA\Property(property: 'investigator', type: 'string', example: 'compliance@finaegis.org'),
        new OA\Property(property: 'resolution', type: 'string', example: 'False positive - legitimate business activity'),
        new OA\Property(property: 'reported_to_authorities', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'SanctionsCheckResult',
    required: ['checked_at', 'status', 'matches'],
    properties: [
        new OA\Property(property: 'checked_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string', enum: ['clear', 'potential_match', 'confirmed_match'], example: 'clear'),
        new OA\Property(
            property: 'matches',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'list_name', type: 'string', example: 'OFAC SDN'),
                    new OA\Property(property: 'match_score', type: 'number', example: 0.95),
                    new OA\Property(property: 'entity_name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'reason', type: 'string', example: 'Name and DOB match'),
                ],
            ),
        ),
        new OA\Property(property: 'next_check_date', type: 'string', format: 'date'),
    ],
)]
class ComplianceSchemas
{
    // This class only contains OpenAPI schema definitions
}
