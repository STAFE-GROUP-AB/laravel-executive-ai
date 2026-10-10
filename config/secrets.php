<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Secret store driver (ADR-003 D3.4)
    |--------------------------------------------------------------------------
    |
    | `supervisor` reads every secret — APP_KEY included — over the Supervisor's
    | peer-verified Unix socket (ADR-003 D3); it is selected whenever the
    | Supervisor passes LEA_SECRETS_SOCKET (runtime-layout.md §3), which is the
    | packaged configuration. `array` is the in-memory store for tests.
    | `unavailable` fails closed: no secret can be read, the IPC answers 503
    | secrets_unavailable, and APP_KEY must come from the environment (a
    | developer checkout with .env). No driver ever reads a secret from a
    | file, a plist or process environment.
    |
    */

    'driver' => env('LEA_SECRETS_DRIVER') ?: (env('LEA_SECRETS_SOCKET') ? 'supervisor' : 'unavailable'),

    /*
    |--------------------------------------------------------------------------
    | Supervisor broker socket
    |--------------------------------------------------------------------------
    */

    'supervisor' => [
        'socket' => env('LEA_SECRETS_SOCKET'),
        'timeout_ms' => 2000,
    ],

];
