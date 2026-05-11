<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ML Anomaly Detection Settings
    |--------------------------------------------------------------------------
    */
    'anomaly_detection' => [
        'enabled'   => env('FRAUD_ANOMALY_DETECTION_ENABLED', false),
        'demo_mode' => env('FRAUD_ANOMALY_DEMO_MODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistical Analysis
    |--------------------------------------------------------------------------
    */
    'statistical' => [
        'z_score_threshold'              => (float) env('FRAUD_Z_SCORE_THRESHOLD', 3.0),
        'iqr_multiplier'                 => (float) env('FRAUD_IQR_MULTIPLIER', 1.5),
        'isolation_forest_contamination' => (float) env('FRAUD_IF_CONTAMINATION', 0.1),
        'lof_neighbors'                  => (int) env('FRAUD_LOF_NEIGHBORS', 20),
        'history_days'                   => 90,
        'min_samples'                    => 10,
        'max_history_size'               => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Behavioral Analysis
    |--------------------------------------------------------------------------
    */
    'behavioral' => [
        'adaptive_sensitivity' => (float) env('FRAUD_ADAPTIVE_SENSITIVITY', 1.5),
        'drift_window_days'    => 7,
        'drift_baseline_days'  => 90,
        'drift_threshold'      => (float) env('FRAUD_DRIFT_THRESHOLD', 0.3),
        'segments'             => [
            'high_value_trader',
            'retail_consumer',
            'occasional_user',
            'new_account',
            'dormant_reactivated',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Velocity Analysis
    |--------------------------------------------------------------------------
    */
    'velocity' => [
        'sliding_windows' => [
            '5m'  => ['minutes' => 5, 'max_count' => 5, 'max_volume' => 10000],
            '15m' => ['minutes' => 15, 'max_count' => 10, 'max_volume' => 25000],
            '1h'  => ['minutes' => 60, 'max_count' => 20, 'max_volume' => 50000],
            '6h'  => ['minutes' => 360, 'max_count' => 50, 'max_volume' => 100000],
            '24h' => ['minutes' => 1440, 'max_count' => 100, 'max_volume' => 250000],
            '7d'  => ['minutes' => 10080, 'max_count' => 500, 'max_volume' => 1000000],
        ],
        'burst_ratio_threshold' => (float) env('FRAUD_BURST_RATIO_THRESHOLD', 3.0),
        'cross_account'         => [
            'enabled'                 => true,
            'shared_device_threshold' => 3,
            'shared_ip_threshold'     => 5,
            'time_window_minutes'     => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Geolocation Analysis
    |--------------------------------------------------------------------------
    */
    'geolocation' => [
        'impossible_travel_max_speed_kmh' => (float) env('FRAUD_MAX_TRAVEL_SPEED', 900.0),
        'ip_reputation_threshold'         => (float) env('FRAUD_IP_REPUTATION_THRESHOLD', 0.6),
        'high_risk_countries'             => array_filter(explode(',', env('FRAUD_HIGH_RISK_COUNTRIES', ''))),
        'geo_cluster'                     => [
            'eps_km'                       => 50.0,
            'min_points'                   => 3,
            'max_points'                   => 1000,
            'max_distance_from_cluster_km' => 500.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
    */
    'batch' => [
        // Must be a 5-field cron expression when used with `Schedule::…->cron()` in `routes/console.php`.
        'schedule'       => env('FRAUD_BATCH_SCHEDULE', '0 */6 * * *'),
        'chunk_size'     => (int) env('FRAUD_BATCH_CHUNK_SIZE', 100),
        'lookback_hours' => (int) env('FRAUD_BATCH_LOOKBACK_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | ML Service
    |--------------------------------------------------------------------------
    */
    'ml' => [
        'enabled'       => env('FRAUD_ML_ENABLED', false),
        'api_endpoint'  => env('FRAUD_ML_API_ENDPOINT', ''),
        'model_version' => env('FRAUD_ML_MODEL_VERSION', 'v1.0'),
    ],
];
