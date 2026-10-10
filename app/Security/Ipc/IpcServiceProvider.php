<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use App\Security\Authorization\OwnerAbilities;
use App\Security\Ipc\OAuth\NoPendingOAuthFlows;
use App\Security\Ipc\OAuth\OAuthCallbackHandler;
use App\Security\Secrets\SecretsServiceProvider;
use App\Security\Secrets\SecretStore;
use App\Support\Install\Tunnel\NotConfiguredTunnelProvider;
use App\Support\Install\TunnelProvider;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the S1-08 server end of the IPC contract: the key-source seam
 * (ADR-003 D3; S1-07 rebinds `IpcKeySource` to the secrets broker), the
 * Sprint 1 stand-ins for the OAuth exchange and the tunnel provider, and the
 * owner abilities every operation authorises against (§11).
 */
final class IpcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IpcKeySource::class, static function (Application $app): IpcKeySource {
            $configured = (string) $app['config']->get('ipc.key_source', 'auto');

            // S1-07: under the Supervisor the secret store is the broker socket and `K` comes
            // from it on every call (ADR-003 D3; ipc-server.md §2).
            return match (true) {
                $configured === 'testing' => new TestingKeySource,
                $configured === 'unavailable' => new UnavailableKeySource,
                $configured === 'broker' => new BrokerKeySource($app->make(SecretStore::class)),
                $app->environment('testing') => new TestingKeySource,
                (string) $app['config']->get('secrets.driver') === SecretsServiceProvider::DRIVER_SUPERVISOR => new BrokerKeySource($app->make(SecretStore::class)),
                default => new UnavailableKeySource,
            };
        });

        $this->app->bindIf(OAuthCallbackHandler::class, NoPendingOAuthFlows::class);
        $this->app->bindIf(TunnelProvider::class, NotConfiguredTunnelProvider::class);
    }

    public function boot(Gate $gate): void
    {
        OwnerAbilities::define($gate);
    }
}
