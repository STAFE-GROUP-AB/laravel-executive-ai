<?php

declare(strict_types=1);

use App\Security\Ipc\BrokerKeySource;
use App\Security\Ipc\IpcKeySource;
use App\Security\Ipc\TestingKeySource;
use App\Security\Ipc\UnavailableKeySource;
use App\Security\Secrets\ArraySecretStore;
use App\Security\Secrets\SecretStore;
use App\Security\Secrets\SupervisorSecretStore;
use App\Security\Secrets\UnavailableSecretStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Tests\Support\FakeSecretsBroker;

/*
 * ADR-003 D3.3 / ADR-001 D4: under the Supervisor there is no .env. The core boots through
 * bootstrap/app.php with LEA_SECRETS_SOCKET and no APP_KEY, and app.key comes from the broker;
 * the IPC key source is the broker too. Without the socket the drivers fail closed.
 */

/**
 * Boots a second application through the real bootstrap with the given environment and hands
 * the container back afterwards. Each of the three environment sources is snapshotted and
 * restored on its own, so the test process keeps APP_ENV=testing exactly as PHPUnit set it.
 */
function bootWithSecretsEnvironment(array $environment, callable $callback): mixed
{
    $testApp = app();
    $previous = [];

    foreach ($environment as $name => $value) {
        $previous[$name] = [
            'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
            'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
            'getenv' => getenv($name) === false ? null : getenv($name),
        ];

        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            continue;
        }

        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    try {
        /** @var Application $app */
        $app = require base_path('bootstrap/app.php');
        // The packaged runtime has no .env (ADR-001 D4). A developer's or CI's .env must not leak
        // into this boot: Dotenv would re-apply a key it loaded for the test application.
        $app->loadEnvironmentFrom('.env.lxai-does-not-exist');
        $app->make(Kernel::class)->bootstrap();

        return $callback($app);
    } finally {
        foreach ($previous as $name => $sources) {
            if ($sources['server'] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $sources['server'];
            }

            if ($sources['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $sources['env'];
            }

            $sources['getenv'] === null ? putenv($name) : putenv("{$name}={$sources['getenv']}");
        }

        Container::setInstance($testApp);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($testApp);
    }
}

it('boots without APP_KEY when the Supervisor passes the broker socket', function (): void {
    $broker = FakeSecretsBroker::start([
        'se.stafe.executive-ai.core.app-key/default' => '{"v":1,"kind":"laravel_app_key","current":"base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=","previous":[],"issued_at":"2026-10-10T16:00:00Z"}',
        'se.stafe.executive-ai.ipc.menubar/default' => '{"v":1,"kind":"hmac_key","key":"'.base64_encode(str_repeat('K', 32)).'","issued_at":"2026-10-10T16:00:00Z"}',
    ]);

    // An explicit empty APP_KEY: Dotenv is immutable, so a developer's or CI's .env cannot
    // put a key back — exactly the packaged runtime's situation, where no .env exists.
    bootWithSecretsEnvironment([
        'APP_KEY' => '',
        'APP_ENV' => 'production',
        'LEA_SECRETS_SOCKET' => $broker->socketPath,
        'LEA_SECRETS_DRIVER' => null,
        'LEA_IPC_KEY_SOURCE' => null,
    ], function (Application $app) use ($broker): void {
        expect($app['config']->get('secrets.driver'))->toBe('supervisor')
            ->and($app['config']->get('app.key'))->toBe('base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=')
            ->and($app['config']->get('app.previous_keys'))->toBe([])
            ->and($app->make(SecretStore::class))->toBeInstanceOf(SupervisorSecretStore::class)
            ->and($app->make(IpcKeySource::class))->toBeInstanceOf(BrokerKeySource::class)
            ->and($app->make(IpcKeySource::class)->key()->bytes())->toBe(str_repeat('K', 32))
            ->and($app->make('encrypter'))->not->toBeNull();

        $requested = array_map(static fn (array $request): string => $request['service'], $broker->requests());
        expect($requested)->toContain('se.stafe.executive-ai.core.app-key')
            ->and($requested)->toContain('se.stafe.executive-ai.ipc.menubar');
    });

    $broker->stop();
});

it('fails closed without a socket outside the test environment', function (): void {
    bootWithSecretsEnvironment([
        'APP_ENV' => 'production',
        'LEA_SECRETS_SOCKET' => null,
        'LEA_SECRETS_DRIVER' => null,
        'LEA_IPC_KEY_SOURCE' => null,
    ], function (Application $app): void {
        expect($app['config']->get('secrets.driver'))->toBe('unavailable')
            ->and($app->make(SecretStore::class))->toBeInstanceOf(UnavailableSecretStore::class)
            ->and($app->make(IpcKeySource::class))->toBeInstanceOf(UnavailableKeySource::class);
    });
});

it('keeps the testing key source and the array store under APP_ENV=testing', function (): void {
    expect(app()->make(IpcKeySource::class))->toBeInstanceOf(TestingKeySource::class)
        ->and(config('secrets.driver'))->toBe('array')
        ->and(app()->make(SecretStore::class))->toBeInstanceOf(ArraySecretStore::class);
});

it('selects the broker key source explicitly when asked', function (): void {
    bootWithSecretsEnvironment([
        'APP_ENV' => 'production',
        'LEA_SECRETS_SOCKET' => null,
        'LEA_SECRETS_DRIVER' => 'array',
        'LEA_IPC_KEY_SOURCE' => 'broker',
    ], function (Application $app): void {
        expect($app->make(IpcKeySource::class))->toBeInstanceOf(BrokerKeySource::class);
    });
});
