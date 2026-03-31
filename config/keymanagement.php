<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Shamir's Secret Sharing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the threshold scheme for key splitting. Default is 2-of-3,
    | meaning any 2 shards can reconstruct the key.
    |
    */
    'shamir' => [
        'total_shards' => (int) env('KEY_MANAGEMENT_TOTAL_SHARDS', 3),
        'threshold'    => (int) env('KEY_MANAGEMENT_THRESHOLD', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | HSM (Hardware Security Module) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the HSM provider for secure storage of auth shards.
    | Supported: "demo", "aws", "azure"
    |
    */
    'hsm' => [
        'provider' => env('HSM_PROVIDER', 'demo'),
        'key_id'   => env('HSM_KEY_ID', 'default'),

        // Signing key ID for ECDSA operations
        'signing_key_id' => env('HSM_SIGNING_KEY_ID', 'signing-default'),

        // AWS KMS settings
        'aws' => [
            'region'          => env('AWS_KMS_REGION', 'us-east-1'),
            'key_arn'         => env('AWS_KMS_KEY_ARN'),
            'signing_key_arn' => env('AWS_KMS_SIGNING_KEY_ARN'),
            'access_key'      => env('AWS_KMS_ACCESS_KEY'),
            'secret_key'      => env('AWS_KMS_SECRET_KEY'),
            'endpoint'        => env('AWS_KMS_ENDPOINT'), // For LocalStack testing
        ],

        // Azure Key Vault settings
        'azure' => [
            'vault_url'        => env('AZURE_KEY_VAULT_URL'),
            'key_name'         => env('AZURE_KEY_VAULT_KEY_NAME'),
            'signing_key_name' => env('AZURE_KEY_VAULT_SIGNING_KEY_NAME'),
            'tenant_id'        => env('AZURE_TENANT_ID'),
            'client_id'        => env('AZURE_CLIENT_ID'),
            'client_secret'    => env('AZURE_CLIENT_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Reconstruction Settings
    |--------------------------------------------------------------------------
    |
    | Configure security settings for key reconstruction.
    |
    */
    'key_ttl_seconds'                      => (int) env('KEY_TTL_SECONDS', 300), // 5 minutes
    'max_reconstruction_attempts_per_hour' => (int) env('KEY_MAX_RECONSTRUCTION_ATTEMPTS', 10),

    /*
    |--------------------------------------------------------------------------
    | Password Derivation Settings
    |--------------------------------------------------------------------------
    |
    | Configure password-based key derivation for recovery shards.
    |
    */
    'password_salt'     => env('KEY_MANAGEMENT_PASSWORD_SALT', 'finaegis-recovery-v1'),
    'pbkdf2_iterations' => (int) env('KEY_MANAGEMENT_PBKDF2_ITERATIONS', 100000),

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, uses simulated HSM instead of real cloud HSM.
    | Default is true for development/testing convenience.
    | Set KEY_MANAGEMENT_DEMO_MODE=false in .env to disable.
    |
    */
    'demo_mode' => (bool) env('KEY_MANAGEMENT_DEMO_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | Audit Settings
    |--------------------------------------------------------------------------
    |
    | Configure audit logging for key operations.
    |
    */
    'audit' => [
        'enabled'        => (bool) env('KEY_MANAGEMENT_AUDIT_ENABLED', true),
        'retention_days' => (int) env('KEY_MANAGEMENT_AUDIT_RETENTION_DAYS', 365),
    ],
];
