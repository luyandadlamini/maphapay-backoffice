<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Legacy admin navigation (FinAegis-era surfaces)
    |--------------------------------------------------------------------------
    |
    | When false, legacy-tagged Filament resources stay hidden from the sidebar
    | except for super-admin. Set MAPHAPAY_SHOW_LEGACY_ADMIN_NAV=true to expose
    | them for finance or platform operators who still need those tools.
    |
    */

    'show_legacy_admin_nav' => filter_var(
        env('MAPPHAPAY_SHOW_LEGACY_ADMIN_NAV', false),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Revenue anomaly scan (REQ-ALR-001)
    |--------------------------------------------------------------------------
    |
    | When true, `revenue:scan-anomalies` sends Filament database notifications
    | to finance-lead and super-admin users if anomalies are detected. You can
    | also pass --notify on the artisan command for one-off runs.
    |
    */

    'revenue_anomaly_scan_send_database_notifications' => filter_var(
        env('MAPPHAPAY_REVENUE_ANOMALY_SCAN_NOTIFY', false),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Revenue admin read cache (REQ-PERF-001, ADR-006 Phase A)
    |--------------------------------------------------------------------------
    |
    | Short TTL for read-mostly aggregates on Filament revenue surfaces (e.g.
    | pricing page fee keys loaded from settings). Tenant cache tags apply when
    | tenancy is initialized.
    |
    */

    'revenue_admin_read_cache_ttl_seconds' => max(
        5,
        (int) env('MAPPHAPAY_REVENUE_ADMIN_READ_CACHE_TTL', 120)
    ),

    /*
    |--------------------------------------------------------------------------
    | Revenue admin activity metrics (REQ-REV-001 / 002, REQ-PERF-001, ADR-006)
    |--------------------------------------------------------------------------
    |
    | Short TTL for tenant-scoped TransactionProjection aggregates shown on
    | Revenue & Performance overview and streams. Max window caps ad-hoc ranges.
    | `revenue_reporting_currency_display` labels the reporting line only;
    | per-asset volumes are not converted to a single headline number.
    |
    */

    'revenue_activity_metrics_ttl_seconds' => max(
        5,
        (int) env('MAPPHAPAY_REVENUE_ACTIVITY_METRICS_TTL', 120)
    ),

    'revenue_activity_max_window_days' => max(
        1,
        (int) env('MAPPHAPAY_REVENUE_ACTIVITY_MAX_WINDOW_DAYS', 93)
    ),

    'revenue_reporting_currency_display' => (string) env('MAPPHAPAY_REVENUE_REPORTING_CURRENCY_DISPLAY', 'ZAR'),

    'revenue_streams_default_activity_window_days' => max(
        1,
        (int) env('MAPPHAPAY_REVENUE_STREAMS_ACTIVITY_WINDOW_DAYS', 30)
    ),

    /*
    |--------------------------------------------------------------------------
    | Revenue COR bridge & unit economics (REQ-REV-003 / 004)
    |--------------------------------------------------------------------------
    |
    | When true, Filament shows the “live” tier (awaiting data or populated slots)
    | for the reserved layouts. Implementations bind
    | {@see \App\Domain\Analytics\Contracts\CorMarginBridgeDataPort} and
    | {@see \App\Domain\Analytics\Contracts\UnitEconomicsDataPort} to real readers.
    | Defaults stay false so operators never see fabricated metrics.
    |
    */

    'revenue_cor_bridge_enabled' => filter_var(
        env('MAPPHAPAY_REVENUE_COR_BRIDGE_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'revenue_unit_economics_enabled' => filter_var(
        env('MAPPHAPAY_REVENUE_UNIT_ECONOMICS_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    /*
    | Stub readers (local + automated tests only — ignored in production).
    | Lets you flip the feature flags and see populated cells without a mart.
    |
    */

    'revenue_cor_bridge_stub_reader' => filter_var(
        env('MAPPHAPAY_REVENUE_COR_BRIDGE_STUB_READER', false),
        FILTER_VALIDATE_BOOL
    ),

    'revenue_unit_economics_stub_reader' => filter_var(
        env('MAPPHAPAY_REVENUE_UNIT_ECONOMICS_STUB_READER', false),
        FILTER_VALIDATE_BOOL
    ),

    /*
    | When true (non-production only), overview + streams activity tiles use fixed
    | STUB numbers so UI can be exercised without transaction_projections rows.
    |
    */

    'revenue_activity_stub_reader' => filter_var(
        env('MAPPHAPAY_REVENUE_ACTIVITY_STUB_READER', false),
        FILTER_VALIDATE_BOOL
    ),
];
