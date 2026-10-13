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

    /*
    |--------------------------------------------------------------------------
    | Authentication (S1-02, docs/architecture/auth.md)
    |--------------------------------------------------------------------------
    |
    | Login is a one-time code by email; there is no password anywhere.
    | `otp.autofill` is only honoured when APP_ENV is `local`: the code is
    | then filled in and verified without opening the mail, so a developer
    | signs in with one click. It is inert in every other environment.
    | `request_rate_limit` bounds how often one address/IP pair may ask for
    | a code; the issuer itself is not rate-limited so the login self-test
    | (health:selftest, every minute) never locks the owner out.
    |
    */

    'auth' => [
        'otp' => [
            'length' => 6,
            'ttl_minutes' => 10,
            'max_attempts' => 5,
            'cooldown_seconds' => 60,
            'autofill' => (bool) env('OTP_AUTOFILL', false),
        ],
        'request_rate_limit' => [
            'max_attempts' => 5,
            'decay_seconds' => 900,
        ],
        /*
        | Passkeys (S2-05, docs/architecture/auth.md §7). `rp.id` is never
        | configured: it is the host the request arrived on, so a credential
        | registered on the loopback origin cannot be used on the tailnet one
        | and the reverse (ADR-006 D3). `ceremony_ttl_seconds` bounds how long
        | a challenge stays answerable.
        */
        'passkeys' => [
            'ceremony_ttl_seconds' => 120,
        ],

        /*
        | Re-authentication for critical actions (§11, threat model T-71).
        | `challenge_ttl_seconds` is how long the owner has to answer a
        | challenge; `window_seconds` is how long the resulting proof is good
        | for. A proof is additionally bound to the session, the action and a
        | single-use nonce, so a merely fresh session never satisfies one.
        */
        'reauth' => [
            'challenge_ttl_seconds' => 300,
            'window_seconds' => 120,
        ],
    ],

];
