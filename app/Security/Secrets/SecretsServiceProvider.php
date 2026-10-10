<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-003 D3.3/D3.4: binds the SecretStore driver and, under the Supervisor,
 * fetches `core.app-key` and sets `app.key` / `app.previous_keys` in memory
 * before the encrypter resolves. There is no .env in the packaged runtime
 * (ADR-001 D4); this is how APP_KEY reaches Laravel. Registered first in
 * bootstrap/providers.php so nothing can resolve the encrypter before it runs.
 */
final class SecretsServiceProvider extends ServiceProvider
{
    public const string DRIVER_SUPERVISOR = 'supervisor';

    public const string DRIVER_ARRAY = 'array';

    public const string DRIVER_UNAVAILABLE = 'unavailable';

    public function register(): void
    {
        $this->app->singleton(SecretStore::class, static function (Application $app): SecretStore {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            return match ((string) $config->get('secrets.driver', self::DRIVER_UNAVAILABLE)) {
                self::DRIVER_SUPERVISOR => new SupervisorSecretStore(
                    (string) $config->get('secrets.supervisor.socket', ''),
                    (int) $config->get('secrets.supervisor.timeout_ms', 2000),
                    $app->make(Redactor::class),
                ),
                self::DRIVER_ARRAY => new ArraySecretStore,
                default => new UnavailableSecretStore,
            };
        });

        $this->loadApplicationKeyFromTheBroker();
    }

    /**
     * Only when the Supervisor runs us and the environment carries no key
     * (it never does in the packaged runtime). A broker failure here is
     * fatal on purpose: a core without APP_KEY must not boot half-way.
     */
    private function loadApplicationKeyFromTheBroker(): void
    {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);

        if ((string) $config->get('secrets.driver') !== self::DRIVER_SUPERVISOR) {
            return;
        }

        if ((string) $config->get('app.key', '') !== '') {
            return;
        }

        /** @var SecretStore $store */
        $store = $this->app->make(SecretStore::class);
        $keys = self::applicationKeys($store->get(SecretRef::appKey())->envelope());

        $config->set('app.key', $keys['current']);
        $config->set('app.previous_keys', $keys['previous']);
    }

    /**
     * The `laravel_app_key` envelope (ADR-003 D1): `current` + `previous[]`.
     *
     * @param  array<string, mixed>  $envelope
     * @return array{current: string, previous: list<string>}
     */
    public static function applicationKeys(array $envelope): array
    {
        $current = $envelope['current'] ?? null;

        if (($envelope['kind'] ?? null) !== 'laravel_app_key' || ! is_string($current) || ! str_starts_with($current, 'base64:')) {
            throw new SecretUnavailableException('The application key envelope is not a laravel_app_key envelope.');
        }

        $decoded = base64_decode(substr($current, 7), true);

        if ($decoded === false || strlen($decoded) !== 32) {
            throw new SecretUnavailableException('The application key is not 32 base64-encoded bytes.');
        }

        $previous = [];

        foreach (is_array($envelope['previous'] ?? null) ? $envelope['previous'] : [] as $key) {
            if (is_string($key) && str_starts_with($key, 'base64:')) {
                $previous[] = $key;
            }
        }

        return ['current' => $current, 'previous' => $previous];
    }
}
