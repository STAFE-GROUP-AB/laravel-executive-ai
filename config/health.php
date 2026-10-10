<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health contract thresholds (docs/architecture/health-contract.md §2, §5)
    |--------------------------------------------------------------------------
    |
    | Defaults are the contract defaults; S1-14 tunes them from measurements.
    | The schema does not fix them, so they may change without a schema bump.
    |
    */

    'thresholds' => [
        'menubar_integration' => (int) env('HEALTH_MENUBAR_THRESHOLD_SECONDS', 120),
        'queue_worker' => (int) env('HEALTH_QUEUE_WORKER_THRESHOLD_SECONDS', 90),
        'scheduler_selftest' => (int) env('HEALTH_SCHEDULER_THRESHOLD_SECONDS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets
    |--------------------------------------------------------------------------
    |
    | Each subcheck may take at most `check_timeout_ms`; a check that exceeds
    | it is reported as fail/"timeout". When `total_budget_ms` is exhausted the
    | remaining checks are reported as skip/"budget exhausted".
    |
    */

    'check_timeout_ms' => 2000,
    'total_budget_ms' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Response cache
    |--------------------------------------------------------------------------
    |
    | /health/ready may be served from one global cache entry for this many
    | seconds, so a menu bar polling every few seconds costs one evaluation.
    |
    */

    'cache_ttl_seconds' => 5,

    /*
    |--------------------------------------------------------------------------
    | Heartbeat intervals (informational, shown in check details)
    |--------------------------------------------------------------------------
    */

    'intervals' => [
        'scheduler_selftest' => 60,
        'queue_worker' => 30,
        'menubar_integration' => 30,
    ],

];
