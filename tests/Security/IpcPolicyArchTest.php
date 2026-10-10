<?php

declare(strict_types=1);

use App\Http\Controllers\Ipc\HealthController;
use App\Http\Controllers\Ipc\HeartbeatController;
use App\Http\Controllers\Ipc\ReservedController;
use App\Http\Middleware\LoopbackOnly;
use App\Security\Authorization\OwnerAbilities;
use App\Security\Ipc\Middleware\IpcAuthenticate;
use App\Security\Ipc\Middleware\IpcRequireElevation;
use App\Security\Ipc\Middleware\IpcThrottle;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * IPC contract §11 / §12 `IpcPolicyArchTest` (threat model T-19, T-35): no
 * IPC route bypasses the server-side policy layer. Every route under /ipc/
 * (and /health/ready) carries IpcAuthenticate with an explicit scope; every
 * operation that touches the domain calls a named ability through the
 * principal; the scope-only set is exactly the contract's; no route under
 * /ipc/ can mutate an approval; and no IPC controller reaches a model
 * provider, the mailer, the CRM or an MCP tool. A route that breaks this
 * shape fails CI.
 */

/** §6: method + path → ability (null = scope only, as the contract table says). */
const IPC_ROUTE_TABLE = [
    'GET ipc/v1/health' => null,
    'GET ipc/v1/briefs/latest' => OwnerAbilities::VIEW_BRIEF,
    'GET ipc/v1/approvals' => OwnerAbilities::VIEW_APPROVALS,
    'POST ipc/v1/ai/pause' => OwnerAbilities::PAUSE_AI,
    'POST ipc/v1/ai/resume' => OwnerAbilities::RESUME_AI,
    'POST ipc/v1/heartbeat' => null,
    'POST ipc/v1/oauth/callback' => OwnerAbilities::COMPLETE_OAUTH,
    'GET ipc/v1/remote-access' => OwnerAbilities::VIEW_REMOTE_ACCESS,
    'POST ipc/v1/remote-access/enable' => OwnerAbilities::ENABLE_REMOTE_ACCESS,
    'POST ipc/v1/remote-access/disable' => OwnerAbilities::DISABLE_REMOTE_ACCESS,
];

const IPC_SCOPE_ONLY_CONTROLLERS = [HealthController::class, HeartbeatController::class, ReservedController::class];

const IPC_FORBIDDEN_IMPORTS = [
    'App\\AI\\', 'App\\MCP\\', 'App\\CRM\\', 'App\\Integrations\\', 'App\\Approvals\\Actions\\',
    'Illuminate\\Support\\Facades\\Mail', 'Illuminate\\Mail\\', 'Illuminate\\Support\\Facades\\Http', 'Illuminate\\Http\\Client\\',
    'Symfony\\Component\\Process\\', 'Laravel\\Ai\\',
];

/** @return array<string, RouteDefinition> "METHOD uri" => route, for every route under ipc/ or health/ready */
function ipcRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'ipc/') && $uri !== 'health/ready') {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes["{$method} {$uri}"] = $route;
        }
    }

    return $routes;
}

function controllerSource(RouteDefinition $route): string
{
    $class = $route->getControllerClass();

    if (! is_string($class) || ! class_exists($class)) {
        throw new RuntimeException("Route {$route->uri()} must use a controller class, found a closure.");
    }

    return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
}

it('registers exactly the contract operations plus the reserved catch-all under /ipc/', function (): void {
    $registered = array_keys(ipcRoutes());
    $expected = [...array_keys(IPC_ROUTE_TABLE), 'GET health/ready'];
    $extra = array_values(array_diff($registered, $expected));

    expect(array_diff($expected, $registered))->toBe([], 'a contract route is missing');

    foreach ($extra as $key) {
        expect($key)->toMatch('#^[A-Z]+ ipc/v1/\{reserved\}$#', "unexpected IPC route {$key}: add it to the contract table and IPC_ROUTE_TABLE");
    }
});

it('puts IpcAuthenticate with an explicit scope on every /ipc/ route and on /health/ready', function (): void {
    foreach (ipcRoutes() as $key => $route) {
        $middleware = $route->gatherMiddleware();
        $auth = array_values(array_filter($middleware, fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, IpcAuthenticate::class.':')));

        expect($auth)->toHaveCount(1, "{$key} must carry exactly one IpcAuthenticate:<scope>");

        $scope = substr($auth[0], strlen(IpcAuthenticate::class) + 1);
        $expectedScope = in_array($key, ['GET ipc/v1/health', 'GET health/ready'], true) ? 'menubar,supervisor' : 'menubar';

        expect($scope)->toBe($expectedScope, "{$key} scope");
    }
});

it('rate-limits every operation with its configured budget', function (): void {
    foreach (ipcRoutes() as $key => $route) {
        if ($key === 'GET health/ready' || str_contains($key, '{reserved}')) {
            continue;
        }

        $throttle = array_values(array_filter($route->gatherMiddleware(), fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, IpcThrottle::class.':')));

        expect($throttle)->toHaveCount(1, "{$key} must carry IpcThrottle:<operation>");

        $operation = substr($throttle[0], strlen(IpcThrottle::class) + 1);

        expect(config("ipc.rate_limits.{$operation}"))->toBeInt("{$key}: no rate limit for {$operation}");
    }
});

it('calls the named ability through the principal in every domain-touching controller', function (): void {
    foreach (ipcRoutes() as $key => $route) {
        if ($key === 'GET health/ready') {
            continue;
        }

        $class = $route->getControllerClass();
        $ability = IPC_ROUTE_TABLE[$key] ?? null;
        $source = controllerSource($route);

        if ($ability === null) {
            expect($class)->toBeIn(IPC_SCOPE_ONLY_CONTROLLERS, "{$key} is scope-only in the contract but uses {$class}");
            expect($source)->not->toContain('->authorize(');

            continue;
        }

        $constant = array_search($ability, (new ReflectionClass(OwnerAbilities::class))->getConstants(), true);

        expect($class)->not->toBeIn(IPC_SCOPE_ONLY_CONTROLLERS, "{$key} must authorise");
        expect(str_contains($source, '->authorize(OwnerAbilities::'))->toBeTrue("{$key} must authorise through IpcPrincipal");
        expect(str_contains($source, "OwnerAbilities::{$constant}"))->toBeTrue("{$key} must authorise `{$ability}`");
    }
});

it('defines every ability the route table names as a gate', function (): void {
    $gate = app(Gate::class);

    foreach (array_filter(IPC_ROUTE_TABLE) as $ability) {
        expect($gate->has($ability))->toBeTrue("gate {$ability} is not defined");
    }
});

it('requires elevation for exactly the contract\'s elevated operations', function (): void {
    foreach (ipcRoutes() as $key => $route) {
        $elevated = array_values(array_filter($route->gatherMiddleware(), fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, IpcRequireElevation::class.':')));

        $key === 'POST ipc/v1/remote-access/enable'
            ? expect($elevated)->toBe([IpcRequireElevation::class.':remote-access-enable'])
            : expect($elevated)->toBe([], "{$key} must not be elevated");
    }
});

it('has no route under /ipc/ that can mutate an approval', function (): void {
    foreach (ipcRoutes() as $key => $route) {
        if (str_contains($route->uri(), 'approvals')) {
            expect($route->methods())->toBe(['GET', 'HEAD'], "{$key} must be read-only");
        }
    }

    foreach (glob(app_path('Http/Controllers/Ipc/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);

        expect(preg_match('/\b(approve|reject|cancel)(Approval|Proposal)\b/i', $source))->toBe(0, basename($file).' must not mutate approvals')
            ->and(str_contains($source, 'App\\Approvals\\Actions'))->toBeFalse(basename($file).' must not reach approval actions');
    }
});

it('never lets an IPC controller start a model run, send mail, write the CRM, call MCP or spawn a process', function (): void {
    foreach (glob(app_path('Http/Controllers/Ipc/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);

        foreach (IPC_FORBIDDEN_IMPORTS as $forbidden) {
            expect(str_contains($source, 'use '.$forbidden))->toBeFalse(basename($file)." imports {$forbidden}");
        }

        expect($source)->not->toMatch('/\b(shell_exec|exec|proc_open|passthru|system)\s*\(/', basename($file).' spawns a process');
    }
});

it('keeps the mutating set to exactly pause, resume, heartbeat, oauth callback and tunnel state', function (): void {
    $mutating = [];

    foreach (ipcRoutes() as $key => $route) {
        if (str_contains($key, '{reserved}') || in_array($route->methods()[0], ['GET', 'HEAD'], true)) {
            continue;
        }

        $mutating[] = $key;
    }

    sort($mutating);

    expect($mutating)->toBe([
        'POST ipc/v1/ai/pause',
        'POST ipc/v1/ai/resume',
        'POST ipc/v1/heartbeat',
        'POST ipc/v1/oauth/callback',
        'POST ipc/v1/remote-access/disable',
        'POST ipc/v1/remote-access/enable',
    ]);
});

it('keeps /health/live open and /health/ready behind both LoopbackOnly and IpcAuthenticate', function (): void {
    $live = Route::getRoutes()->getByName('health.live');
    $ready = Route::getRoutes()->getByName('health.ready');

    $liveMiddleware = array_values(array_filter($live?->gatherMiddleware() ?? [], fn (mixed $entry): bool => $entry !== 'api'));

    expect($liveMiddleware)->toBe([LoopbackOnly::class])
        ->and($ready?->gatherMiddleware())->toContain(LoopbackOnly::class)
        ->and($ready?->gatherMiddleware())->toContain(IpcAuthenticate::class.':menubar,supervisor');
});
