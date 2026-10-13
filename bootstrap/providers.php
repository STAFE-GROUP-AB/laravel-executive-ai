<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Security\Ipc\IpcServiceProvider;
use App\Security\Secrets\SecretsServiceProvider;

return [
    // First: under the Supervisor it sets app.key from the broker before anything resolves the encrypter.
    SecretsServiceProvider::class,
    AppServiceProvider::class,
    AiServiceProvider::class,
    IpcServiceProvider::class,
];
