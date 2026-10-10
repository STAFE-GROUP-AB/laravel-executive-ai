<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backend profile (ADR-002 D1)
    |--------------------------------------------------------------------------
    |
    | `local` is the only profile the installer produces: one SQLite file in
    | WAL mode, the database queue, database cache and sessions. `server` is
    | the operator-run MySQL/Redis profile exercised by the contract-test
    | matrix (ADR-002 D10); the Mac installer never writes it.
    |
    */

    'backend_profile' => env('LEA_BACKEND_PROFILE', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Privacy mode (blueprint §4, §10; ADR-004 D4/D5)
    |--------------------------------------------------------------------------
    |
    | Reported verbatim by /health/ready and shown in the menu bar and the PWA.
    | Values follow the health contract: LOCAL_ONLY, EU_RESTRICTED, CUSTOM.
    |
    */

    'privacy_mode' => env('LEA_PRIVACY_MODE', 'LOCAL_ONLY'),

    /*
    |--------------------------------------------------------------------------
    | Selected AI provider
    |--------------------------------------------------------------------------
    |
    | The adapter the owner selected in SELECT_AI (§12.1), or null when none.
    | Selecting a provider never makes the install AI-ready: ai_ready requires
    | a recorded successful completion (health contract §3). Never a key.
    |
    */

    'ai' => [
        'provider' => env('LEA_AI_PROVIDER') ?: null,
    ],

];
