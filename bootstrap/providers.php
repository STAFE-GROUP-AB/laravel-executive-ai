<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;
use App\Security\Egress\EgressServiceProvider;
use App\Security\Ipc\IpcServiceProvider;
use App\Security\Secrets\SecretsServiceProvider;

return [
    // First: under the Supervisor it sets app.key from the broker before anything resolves the encrypter.
    SecretsServiceProvider::class,
    AppServiceProvider::class,
    // Before the AI layer: its `boot()` installs the ADR-004 D4 layer-3 HTTP veto,
    // and nothing may make an outbound request before the veto is in the stack.
    EgressServiceProvider::class,
    AiServiceProvider::class,
    // Before IpcServiceProvider, so the audit hook is on the Gate before OwnerAbilities defines its
    // abilities: an `after` hook registered later would still fire, but keeping the order explicit
    // means the §11 surfaces and the §6 IPC operations are audited by construction, not by luck.
    AuthorizationServiceProvider::class,
    IpcServiceProvider::class,
];
