<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | Set TRUSTCERT_ALLOW_DEMO=false in .env to disallow demo mode in production.
    | Default is true for development/testing convenience.
    |
    */
    'allow_demo' => (bool) env('TRUSTCERT_ALLOW_DEMO', true),

    /*
    |--------------------------------------------------------------------------
    | Certificate Authority Configuration
    |--------------------------------------------------------------------------
    */
    'certificate_authority' => [
        'ca_id'            => env('TRUSTCERT_CA_ID', 'finaegis-root-ca'),
        'ca_signing_key'   => env('TRUSTCERT_CA_SIGNING_KEY', 'demo-ca-signing-key-for-testing'),
        'default_validity' => [
            'days' => 365,
        ],
        'max_chain_depth' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential Signing Configuration
    |--------------------------------------------------------------------------
    */
    'credentials' => [
        'credential_signing_key'   => env('TRUSTCERT_CREDENTIAL_SIGNING_KEY'),
        'presentation_signing_key' => env('TRUSTCERT_PRESENTATION_SIGNING_KEY'),
        'default_issuer'           => env('TRUSTCERT_DEFAULT_ISSUER', 'did:finaegis:issuer:default'),
        'supported_proof_types'    => [
            'Ed25519Signature2020',
            'JsonWebSignature2020',
        ],
        'context_urls' => [
            'https://www.w3.org/2018/credentials/v1',
            'https://www.w3.org/ns/did/v1',
            'https://w3id.org/security/suites/ed25519-2020/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Revocation Registry Configuration
    |--------------------------------------------------------------------------
    */
    'revocation' => [
        'enabled'            => true,
        'cache_ttl'          => 300, // seconds
        'batch_check_limit'  => 100,
        'status_list_format' => 'StatusList2021',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trust Framework Configuration
    |--------------------------------------------------------------------------
    */
    'trust_framework' => [
        'enabled'              => true,
        'require_chain'        => false, // Require complete chain for verification
        'max_chain_depth'      => 10,
        'default_trust_level'  => 'basic',
        'allowed_issuer_types' => [
            'root_ca',
            'intermediate_ca',
            'issuing_ca',
            'trusted_issuer',
            'delegated_issuer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trust Level Requirements
    |--------------------------------------------------------------------------
    */
    'trust_levels' => [
        'unknown' => [
            'requirements' => [],
        ],
        'basic' => [
            'requirements' => [
                'email_verified' => true,
            ],
        ],
        'verified' => [
            'requirements' => [
                'email_verified'    => true,
                'identity_verified' => true,
            ],
        ],
        'high' => [
            'requirements' => [
                'email_verified'    => true,
                'identity_verified' => true,
                'kyc_completed'     => true,
            ],
        ],
        'ultimate' => [
            'requirements' => [
                'email_verified'    => true,
                'identity_verified' => true,
                'kyc_completed'     => true,
                'audit_completed'   => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Options
    |--------------------------------------------------------------------------
    */
    'verification' => [
        'check_expiration' => true,
        'check_revocation' => true,
        'verify_proof'     => true,
        'verify_issuer'    => true,
        'cache_results'    => true,
        'cache_ttl'        => 60, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | W3C Standards Compliance
    |--------------------------------------------------------------------------
    */
    'w3c' => [
        'vc_data_model_version' => '1.1',
        'did_method'            => 'did:finaegis',
        'status_list_version'   => '2021',
    ],
];
