<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Swaps `core.app-key` under a running application, the way the Supervisor handing
 * `SecretsServiceProvider` a different `laravel_app_key` envelope does (ADR-003 D3.3,
 * D8) — the manoeuvre every S2-04 test about rotation, reinstall or a lost key needs.
 *
 * It is a support class rather than a helper function in one test file because three
 * files need it and Pest loads them into the same process, where a second
 * `function withApplicationKey()` is a fatal error.
 */
final class ApplicationKeys
{
    /** A key of the configured cipher's length, in the `base64:` form config uses. */
    public static function fresh(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
    }

    /**
     * Runs `$assertions` with `app.key` and `app.previous_keys` set to this envelope,
     * and puts the old ones back whatever happens.
     *
     * @param  list<string>  $previous
     * @param  Closure():void  $assertions
     */
    public static function with(string $key, array $previous, Closure $assertions): void
    {
        $originalKey = config('app.key');
        $originalPrevious = config('app.previous_keys');

        self::rebuild($key, $previous);

        try {
            $assertions();
        } finally {
            self::rebuild($originalKey, $originalPrevious);
        }
    }

    /**
     * Both halves are needed, and the second is easy to miss: `encrypter` is a
     * container singleton **and** the `Crypt` facade caches its resolved instance
     * separately. `Model::currentEncrypter()` reads the facade root, so forgetting
     * only the container instance leaves the models decrypting with the old key — a
     * key swap that silently does nothing, which is exactly how a test like this
     * passes while proving the opposite of what it claims.
     *
     * One *named* instance is cleared, never `clearResolvedInstances()`, which would
     * drop every facade the calling test is standing on.
     */
    public static function rebuild(mixed $key, mixed $previous): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);

        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }
}
