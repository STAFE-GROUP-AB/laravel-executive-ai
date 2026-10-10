<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Loopback port (IPC contract §1.1, ADR-001 D3)
    |--------------------------------------------------------------------------
    |
    | The fixed loopback port chosen at preflight. The core binds 127.0.0.1
    | only (the Caddyfile, S1-06); PHP needs the number to enforce the §5.2
    | rule that `Host` is exactly `127.0.0.1:<port>`. 8787 is the placeholder
    | the contract and the fixtures use.
    |
    */

    'port' => (int) env('LEA_IPC_PORT', 8787),

    /*
    |--------------------------------------------------------------------------
    | Key source (ADR-003 D3, IPC contract §3.1)
    |--------------------------------------------------------------------------
    |
    | How the core obtains `K`, the 256-bit HMAC key the Supervisor wrote to
    | the Keychain. `auto` selects the testing source (K_test, §4.6) under
    | APP_ENV=testing and the fail-closed `unavailable` source everywhere
    | else until S1-07 binds the secrets-broker source. `K` is never read from
    | .env, a plist or a file.
    |
    */

    'key_source' => env('LEA_IPC_KEY_SOURCE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Versioning (§5.1)
    |--------------------------------------------------------------------------
    */

    'supported_versions' => [1],

    /*
    |--------------------------------------------------------------------------
    | Time window and replay protection (§4.1, §4.3)
    |--------------------------------------------------------------------------
    */

    'skew_seconds' => 30,
    'nonce_window_seconds' => 120,
    'elevation_ttl_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Sizes (§1.1)
    |--------------------------------------------------------------------------
    */

    'max_request_body_bytes' => 64 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Rate limits per session, per minute (§9)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'health' => 30,
        'briefs-latest' => 30,
        'approvals' => 30,
        'ai-pause' => 10,
        'ai-resume' => 10,
        'heartbeat' => 6,
        'oauth-callback' => 5,
        'remote-access' => 10,
        'remote-access-enable' => 3,
        'remote-access-disable' => 3,
        'all' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat (§6.5) and server identity reported to the client
    |--------------------------------------------------------------------------
    */

    'heartbeat' => [
        'next_in_seconds' => 30,
    ],

    'server' => [
        'runtime_version' => env('LEA_RUNTIME_VERSION', PHP_VERSION),
    ],

];
