<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureNoOwnerExists;
use App\Http\Middleware\ResolveRequestOrigin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;

/*
 * Architecture pins for the S1-02 route contract that S1-04 and S1-05 build
 * on: the auth file is required from routes/web.php, the signed-in paths
 * carry `auth`, nothing password-shaped exists, and the hardening
 * middleware wraps the web group.
 */

function namedRoute(string $name): RouteInstance
{
    $route = Route::getRoutes()->getByName($name);

    if ($route === null) {
        throw new RuntimeException("Route {$name} is not registered.");
    }

    return $route;
}

it('requires routes/auth.php from routes/web.php with one line', function (): void {
    expect((string) file_get_contents(base_path('routes/web.php')))->toContain("require __DIR__.'/auth.php';");
});

it('protects /home and /briefs with auth and the auth pages with guest and no-store', function (): void {
    expect(namedRoute('home')->uri())->toBe('home')
        ->and(namedRoute('home')->gatherMiddleware())->toContain('auth')->toContain('web')
        ->and(namedRoute('briefs')->uri())->toBe('briefs')
        ->and(namedRoute('briefs')->gatherMiddleware())->toContain('auth')
        ->and(namedRoute('login')->gatherMiddleware())->toContain('guest')->toContain('cache.headers:no_store')
        ->and(namedRoute('logout')->methods())->toBe(['POST'])
        ->and(namedRoute('logout')->gatherMiddleware())->toContain('auth')
        ->and(namedRoute('install.owner')->gatherMiddleware())->toContain(EnsureNoOwnerExists::class);
});

it('registers no password route of any kind', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn (RouteInstance $route): string => (string) $route->getName().' '.$route->uri());

    expect($names->filter(fn (string $route): bool => str_contains(strtolower($route), 'password'))->all())->toBe([]);
});

it('wraps the web group in ResolveRequestOrigin first and SecurityHeaders last', function (): void {
    $web = app(Kernel::class)->getMiddlewareGroups()['web'];

    expect($web[0])->toBe(ResolveRequestOrigin::class)
        ->and($web[count($web) - 1])->toBe(SecurityHeaders::class);
});
