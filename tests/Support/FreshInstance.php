<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Runtime\RuntimeLayout;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Support\Ipc\IpcClient;

/**
 * A freshly installed core for the E2E slice (S1-15, blueprint §16 step 8):
 * an installer-shaped data directory in the temp directory, a second
 * application booted through the real bootstrap/app.php against it, and the
 * first `migrate` the Supervisor's first core start performs. Nothing is
 * carried over from the suite's own application; the suite's database
 * (database/testing.sqlite, DatabaseTransactions) is never touched.
 *
 * Same mechanics as the reinstall proof in
 * tests/Feature/Install/ReinstallPreservesDataTest.php: DB_DATABASE is pinned
 * to the ADR-001 D4 default <LEA_DATA_DIR>/database/executive.sqlite because a
 * developer checkout's .env would otherwise win over that default, and every
 * environment source is restored afterwards so the rest of the process is
 * unaffected. The directory is deleted by `destroy()`.
 */
final class FreshInstance
{
    /**
     * The browser origin the product opens (ADR-006 D3): the same loopback
     * socket as `IpcClient::ORIGIN` under the host name a browser can hold a
     * passkey for. Browser requests use this; `IpcClient::ORIGIN` keeps the
     * literal `127.0.0.1` the IPC contract requires (§1.1) and is what
     * `ipc()` below sends on. The two are deliberately different hosts on the
     * same port (`config('ipc.port')`, 8787 in the fixtures).
     */
    public const string ORIGIN = 'http://localhost:8787';

    /** @var array{server: ?string, env: ?string, putenv: string|false} */
    private array $savedDatabase = ['server' => null, 'env' => null, 'putenv' => false];

    /** @var array{server: ?string, env: ?string, putenv: string|false} */
    private array $savedDataDir = ['server' => null, 'env' => null, 'putenv' => false];

    private function __construct(public readonly string $dataDirectory) {}

    /** The directories the Mac installer creates (InstallPaths.dataSubdirectories), then the process is pointed at them. */
    public static function create(): self
    {
        $directory = sys_get_temp_dir().'/lxai-slice-'.bin2hex(random_bytes(6));

        foreach (['database', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'backups'] as $subdirectory) {
            File::ensureDirectoryExists("{$directory}/{$subdirectory}");
        }

        $instance = new self($directory);
        $instance->pointAt();

        return $instance;
    }

    public function databasePath(): string
    {
        return RuntimeLayout::databasePath($this->dataDirectory).'/'.RuntimeLayout::DATABASE_FILE;
    }

    /**
     * One boot: a fresh application through bootstrap/app.php with the console
     * kernel bootstrapped and pending migrations applied. The container
     * singleton and the facade root are handed back to the suite's application
     * afterwards.
     *
     * @template T
     *
     * @param  callable(Application): T  $callback
     * @return T
     */
    public function boot(callable $callback): mixed
    {
        $testApp = app();

        try {
            /** @var Application $app */
            $app = require base_path('bootstrap/app.php');
            $app->make(ConsoleKernel::class)->bootstrap();
            $app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);

            return $callback($app);
        } finally {
            Container::setInstance($testApp);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($testApp);
        }
    }

    /**
     * One browser request against the booted core, through its HTTP kernel —
     * the same pipeline the loopback server runs (web middleware group,
     * TrustLoopbackHosts, session, CSRF, security headers).
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $server
     */
    public static function request(Application $app, string $method, string $uri, array $headers = [], array $server = [], ?string $content = null): TestResponse
    {
        $request = Request::create(self::ORIGIN.$uri, $method, [], [], [], ['REMOTE_ADDR' => '127.0.0.1', ...$server], $content);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $kernel = $app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }

    /**
     * One signed IPC request against the booted core, as the menu bar or the
     * Supervisor sends it (IpcClient does the §4.2 arithmetic).
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $options
     */
    public static function ipc(Application $app, string $method, string $pathAndQuery, ?array $body = null, array $options = []): TestResponse
    {
        $prepared = IpcClient::prepare($method, $pathAndQuery, $body, $options);
        $request = Request::create($prepared['url'], $method, [], [], [], $prepared['server'], $prepared['content']);

        $kernel = $app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }

    /** Restores the environment and deletes the data directory. */
    public function destroy(): void
    {
        self::restore(RuntimeLayout::DATA_DIR_ENV, $this->savedDataDir);
        self::restore('DB_DATABASE', $this->savedDatabase);

        File::deleteDirectory($this->dataDirectory);
    }

    /**
     * Points the process at the data directory the way the Supervisor does
     * (runtime-layout.md §3), pinning DB_DATABASE to exactly the path the D4
     * default resolves to.
     */
    private function pointAt(): void
    {
        $this->savedDataDir = self::snapshot(RuntimeLayout::DATA_DIR_ENV);
        $this->savedDatabase = self::snapshot('DB_DATABASE');

        $_SERVER[RuntimeLayout::DATA_DIR_ENV] = $this->dataDirectory;
        $_ENV[RuntimeLayout::DATA_DIR_ENV] = $this->dataDirectory;
        putenv(RuntimeLayout::DATA_DIR_ENV.'='.$this->dataDirectory);

        $_SERVER['DB_DATABASE'] = $this->databasePath();
        unset($_ENV['DB_DATABASE']);
        putenv('DB_DATABASE');
    }

    /** @return array{server: ?string, env: ?string, putenv: string|false} */
    private static function snapshot(string $name): array
    {
        return [
            'server' => isset($_SERVER[$name]) && is_string($_SERVER[$name]) ? $_SERVER[$name] : null,
            'env' => isset($_ENV[$name]) && is_string($_ENV[$name]) ? $_ENV[$name] : null,
            'putenv' => getenv($name),
        ];
    }

    /** @param array{server: ?string, env: ?string, putenv: string|false} $saved */
    private static function restore(string $name, array $saved): void
    {
        if (is_string($saved['server'])) {
            $_SERVER[$name] = $saved['server'];
        } else {
            unset($_SERVER[$name]);
        }

        if (is_string($saved['env'])) {
            $_ENV[$name] = $saved['env'];
        } else {
            unset($_ENV[$name]);
        }

        is_string($saved['putenv']) ? putenv("{$name}={$saved['putenv']}") : putenv($name);
    }
}
