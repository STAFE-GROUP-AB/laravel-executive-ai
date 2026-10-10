<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use App\Security\Authorization\OwnerAbilities;
use App\Security\Ipc\OAuth\NoPendingOAuthFlows;
use App\Security\Ipc\OAuth\OAuthCallbackHandler;
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

            return match (true) {
                $configured === 'testing' => new TestingKeySource,
                $configured === 'unavailable' => new UnavailableKeySource,
                $app->environment('testing') => new TestingKeySource,
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
