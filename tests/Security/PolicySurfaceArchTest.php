<?php

declare(strict_types=1);

use App\AI\Tools\PolicyDecision;
use App\AI\Tools\ToolKind;
use App\AI\Tools\ToolRunOutcome;
use App\Policies\BusinessObjectPolicy;
use App\Security\Authorization\Gates\Abilities;
use App\Security\Authorization\Gates\AuthorizesQueuedWork;
use App\Security\Authorization\Gates\SurfaceGates;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Tests\Security\Support\PolicySurface;
use Tests\Security\Support\Surfaces\CustomerNotesTool;
use Tests\Security\Support\Surfaces\GuardedTenantJob;
use Tests\Security\Support\Surfaces\UngatedTool;
use Tests\Security\Support\Surfaces\UnguardedTenantJob;
use Tests\Security\Support\TenantRowFactory;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| The property worth more than any single policy (S2-06, issue #88)
|--------------------------------------------------------------------------
|
| §11 requires a policy or gate in every HTTP action, Livewire action, queued
| job, MCP tool, AI tool and integration call. Sprint 1 proved the shape on
| one surface with `IpcPolicyArchTest`; Sprint 2 multiplied the surfaces by an
| order of magnitude, and a hand-written list of what is guarded goes stale in
| the first week. So the enforcement is structural, and this file is the
| enforcement.
|
| How it decides what needs a policy — three questions, all answered by
| reading the repository rather than a registry:
|
|  1. **Which tables are tenant data?** Every table in the schema with an
|     `organization_id` column (`TenantRowFactory::tenantTables()`). A
|     migration adding one is in scope the moment it runs.
|  2. **Which of those have a policy?** The model for the table is discovered
|     by instantiating every model class under `app/` and asking it for its
|     table; the policy is then whatever Laravel's own discovery resolves for
|     that model. A table with a model and no policy fails. A table with no
|     model yet is allowed, *and* the test asserts it really has no model, so
|     the exemption disappears by itself the day somebody writes one.
|  3. **Which surfaces reach tenant data?** A route handler, Livewire
|     component, queued job or tool class whose source names one of those
|     models or calls `DB::table()`. Such a surface must name an ability.
|
| The detectors in `PolicySurface` are themselves tested here, against the
| deliberately unguarded fixtures in `tests/Security/Support/Surfaces` — the
| last `it()` in this file. The `/ipc/` surface is not re-checked here;
| `IpcPolicyArchTest` owns it, in more detail than a general rule could.
|
*/

/**
 * The non-IPC HTTP surface: "METHOD uri" => the ability the handler must name,
 * or null for a surface that reaches no tenant data and needs none.
 *
 * This is the one table in the file that is written by hand, and it is written
 * by hand on purpose: a *new route* must be a deliberate decision, so an
 * unlisted route fails the first test below rather than being silently
 * accepted. The `/ipc/` table in `IpcPolicyArchTest` is the same idea.
 */
const PWA_ROUTE_TABLE = [
    'GET home' => Abilities::VIEW_HOME,
    'GET briefs' => Abilities::VIEW_BRIEFS,
    'GET briefs/{brief}' => Abilities::VIEW,
    'GET briefs/{brief}/parity.json' => Abilities::VIEW,
    'GET briefs/{brief}/preview/mail' => Abilities::VIEW,
    'GET briefs/{brief}/preview/text' => Abilities::VIEW,
    'GET settings/ai' => Abilities::VIEW_AI_SETTINGS,
    'GET settings/security' => Abilities::VIEW_SECURITY_SETTINGS,
    // No tenant data in reach: the owner-creation page is guarded by
    // EnsureNoOwnerExists, /login by the guest middleware, /logout only ends
    // the caller's own session, /offline carries no per-user state at all.
    'GET install/owner' => null,
    'GET login' => null,
    'POST logout' => null,
    'GET offline' => null,
];

/** Namespaces whose executable classes are tools and must implement `GatedTool`. */
const TOOL_NAMESPACES = ['App\\AI\\Tools\\', 'App\\MCP\\'];

/** @return array<string, RouteDefinition> "METHOD uri" => route, for every route this file owns */
function pwaRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        $action = $route->getActionName();

        // IpcPolicyArchTest owns /ipc/ and /health/; the framework owns its own
        // routes (Livewire's endpoints, the storage fallback, the / redirect).
        if (str_starts_with($uri, 'ipc/') || str_starts_with($uri, 'health/') || str_starts_with($uri, 'livewire/') || str_starts_with($uri, 'storage/')) {
            continue;
        }

        if ($uri === '/' || ($action !== 'Closure' && ! str_starts_with($action, 'App\\'))) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes["{$method} {$uri}"] = $route;
        }
    }

    return $routes;
}

/**
 * The middleware of a route with every alias resolved to its class, the way
 * `IpcPolicyArchTest` reads it: `gatherMiddleware()` returns `auth` and
 * `guest` as the strings the route was written with, and a structural test has
 * to reason about `Authenticate` and `RedirectIfAuthenticated` themselves.
 *
 * @return list<string>
 */
function resolvedMiddleware(RouteDefinition $route): array
{
    $resolved = [];

    foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
        $resolved[] = is_string($middleware) ? explode(':', $middleware, 2)[0] : $middleware::class;
    }

    return $resolved;
}

/** @return list<class-string<Component>> */
function livewireComponents(): array
{
    return PolicySurface::classesImplementing(Component::class);
}

it('has every route in the ability table — a new route fails until it is a decision', function (): void {
    $registered = array_keys(pwaRoutes());
    $expected = array_keys(PWA_ROUTE_TABLE);

    expect(array_values(array_diff($expected, $registered)))->toBe([], 'a route in PWA_ROUTE_TABLE is no longer registered')
        ->and(array_values(array_diff($registered, $expected)))->toBe(
            [],
            'a route is registered with no entry in PWA_ROUTE_TABLE: add it with the ability its handler authorises, or with null if it reaches no tenant data',
        );
})->group('security');

it('names the ability in every handler that reaches tenant data, and requires auth for it', function (): void {
    foreach (pwaRoutes() as $key => $route) {
        $ability = PWA_ROUTE_TABLE[$key] ?? null;
        $class = $route->getControllerClass();

        if ($class === null) {
            expect($ability)->toBeNull("{$key} is a closure, so it cannot authorise; it must reach no tenant data");

            continue;
        }

        $source = PolicySurface::sourceOf($class);

        if ($ability === null) {
            expect(PolicySurface::needsAbility($source))->toBeFalse(
                "{$key} is listed as needing no ability, but {$class} reaches tenant data: give it one",
            );

            continue;
        }

        expect(PolicySurface::authorizes($source))->toBeTrue("{$key}: {$class} must run an authorization check")
            ->and(in_array($ability, PolicySurface::abilitiesNamedIn($source), true))->toBeTrue("{$key}: {$class} must authorise `{$ability}`")
            ->and(in_array(Authenticate::class, resolvedMiddleware($route), true))->toBeTrue("{$key} must sit behind the auth middleware");
    }
})->group('security');

it('runs a named ability in every Livewire action that is not a guest surface', function (): void {
    $routed = [];

    foreach (pwaRoutes() as $route) {
        $routed[$route->getActionName()] = resolvedMiddleware($route);
    }

    foreach (livewireComponents() as $component) {
        $guards = array_values(array_filter(
            $routed[$component] ?? [],
            static fn (string $middleware): bool => in_array($middleware, PolicySurface::GUEST_GUARDS, true),
        ));

        $actions = PolicySurface::livewireActions($component);

        expect($actions)->not->toBeEmpty("{$component} declares no action; remove it or give it one");

        if ($guards !== []) {
            // A guest surface: there is no user to authorize, and the guard on
            // the route is what keeps it unreachable once there is one.
            foreach ($actions as $action) {
                expect(PolicySurface::authorizes(PolicySurface::methodSource($action)))->toBeFalse(
                    "{$component}::{$action->getName()} is a guest surface ({$guards[0]}) and cannot authorise a user",
                );
            }

            continue;
        }

        foreach ($actions as $action) {
            $source = PolicySurface::methodSource($action);

            expect(PolicySurface::authorizes($source))->toBeTrue(
                "{$component}::{$action->getName()} is reachable from the browser and runs no authorization check",
            )->and(PolicySurface::abilitiesNamedIn($source))->not->toBe(
                [],
                "{$component}::{$action->getName()} authorises nothing by name; use an Abilities constant",
            );
        }
    }
})->group('security');

it('authorizes in every queued job that reaches tenant data, and nowhere else', function (): void {
    foreach (PolicySurface::queuedJobs() as $job) {
        $source = PolicySurface::sourceOf($job);

        if (! PolicySurface::needsAbility($source)) {
            expect(PolicySurface::authorizes($source))->toBeFalse(
                "{$job} reaches no tenant data but authorises something; either it does touch a row (then say which) or drop the check",
            );

            continue;
        }

        expect(in_array(AuthorizesQueuedWork::class, class_uses_recursive($job), true))->toBeTrue(
            "{$job} reaches tenant data: use AuthorizesQueuedWork so it runs as the user that dispatched it",
        )->and(str_contains($source, 'authorizeJob('))->toBeTrue("{$job} must call authorizeJob() before it touches the row")
            ->and(PolicySurface::abilitiesNamedIn($source))->not->toBe([], "{$job} must name the ability it needs");
    }
})->group('security');

it('makes every executable tool a GatedTool', function (): void {
    $tools = PolicySurface::toolClasses(TOOL_NAMESPACES);

    foreach ($tools as $tool) {
        expect(PolicySurface::isGatedTool($tool))->toBeTrue(
            "{$tool} is executable in a tool namespace and must implement GatedTool, so ToolAuthorizer decides before it runs",
        );
    }

    // Sprint 2 ships no tool yet, so the loop above is empty and would be a
    // test that asserts nothing. These two assertions are the ones that make
    // it an enforcement mechanism rather than a placeholder: the namespaces it
    // watches exist, and the value objects already in them (the `ToolKind`,
    // `PolicyDecision` and `ToolRunOutcome` enums) are correctly *not* read as
    // tools — so the first real tool is the first class the rule speaks to.
    expect(TOOL_NAMESPACES)->not->toBeEmpty()
        ->and($tools)->not->toContain(ToolKind::class, PolicyDecision::class, ToolRunOutcome::class);
})->group('security');

/**
 * The sweep below is only as good as the schema it reads, and on MySQL
 * `Schema::getTables()` with no schema argument reads the **wrong** one: the
 * grammar excludes the system schemas and returns every table in every other
 * database on the server. A developer machine running Herd has thousands, several
 * hundred carrying an `organization_id`, so the policy sweep enumerated tables that
 * do not exist in the database under test.
 *
 * Neither profile could show it on its own. SQLite cannot: one connection is one
 * file. CI cannot: its MySQL holds only the test database, so the sweep was right
 * by coincidence rather than by construction — which is the kind of green that
 * teaches nothing.
 *
 * This asserts the property directly, so the sweep stays driven by the schema under
 * test. It fails on any machine that has a second database, which is where the
 * defect actually appears.
 */
it('sweeps only the database under test, not every database on the server', function (): void {
    $swept = TenantRowFactory::tenantTables();

    expect($swept)->not->toBeEmpty('the sweep found no tenant table at all, which means it is reading nothing');

    $ownListing = Schema::getTableListing(schema: Schema::getCurrentSchemaListing(), schemaQualified: false);
    $foreign = array_values(array_diff($swept, $ownListing));

    expect($foreign)->toBe([], 'the sweep returned tables that are not in this connection\'s own schema: '.implode(', ', array_slice($foreign, 0, 10)));

    foreach ($swept as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} was swept but does not exist on this connection");
    }
})->group('security')->group('backend-contract');

it('gives every table that carries organization_id a policy, or proves it has no model yet', function (): void {
    $gate = app(Gate::class);
    $models = TenantRowFactory::modelsByTable();
    $withoutModel = [];

    foreach (TenantRowFactory::tenantTables() as $table) {
        $model = $models[$table] ?? null;

        if ($model === null) {
            $withoutModel[] = $table;

            continue;
        }

        $policy = $gate->getPolicyFor($model);

        expect($policy)->not->toBeNull("{$table}: {$model} has no policy — Laravel discovers App\\Policies\\…\\".class_basename($model).'Policy')
            ->and($policy)->toBeInstanceOf(BusinessObjectPolicy::class, "{$table}: the policy for {$model} must extend BusinessObjectPolicy so the tenant rule is the shared one");
    }

    // The exemption is self-liquidating: these tables are exempt *because* they
    // have no model, and the assertion above runs the moment one appears.
    foreach ($withoutModel as $table) {
        expect($models)->not->toHaveKey($table, "{$table} now has a model and needs a policy");
    }

    expect(count($withoutModel) + count(array_intersect(array_keys($models), TenantRowFactory::tenantTables())))
        ->toBe(count(TenantRowFactory::tenantTables()), 'every tenant table is either policed or model-less');
})->group('security');

it('answers all seven CRUD abilities in every business-object policy', function (): void {
    foreach (PolicySurface::applicationClasses() as $class) {
        if (! is_subclass_of($class, BusinessObjectPolicy::class)) {
            continue;
        }

        foreach (Abilities::CRUD as $ability) {
            expect(method_exists($class, $ability))->toBeTrue("{$class} must answer `{$ability}`");
        }
    }
})->group('security');

it('has no admin bypass: no before hook on the Gate and none on any policy', function (): void {
    $gate = app(Gate::class);
    $before = (new ReflectionClass($gate))->getProperty('beforeCallbacks')->getValue($gate);

    expect($before)->toBe([], 'a Gate::before() hook can answer true for a row outside the user\'s memberships — that is the admin bypass §11 forbids');

    foreach (PolicySurface::applicationClasses() as $class) {
        if (! is_subclass_of($class, BusinessObjectPolicy::class)) {
            continue;
        }

        expect(method_exists($class, 'before'))->toBeFalse("{$class}::before() would short-circuit the tenant check for some role");
    }
})->group('security');

it('defines every ability that is named anywhere, as a gate or as a policy method', function (): void {
    $gate = app(Gate::class);

    foreach (SurfaceGates::signedInAbilities() as $ability) {
        expect($gate->has($ability))->toBeTrue("gate {$ability} is not defined");
    }

    expect($gate->has(Abilities::ACCESS_ORGANIZATION))->toBeTrue('the tenant gate is not defined');

    foreach (array_filter(PWA_ROUTE_TABLE) as $key => $ability) {
        $answered = $gate->has($ability) || in_array($ability, Abilities::CRUD, true);

        expect($answered)->toBeTrue("{$key} names `{$ability}`, which neither a gate nor a policy answers");
    }
})->group('security');

it('recognises an unguarded surface — the detectors, proved against fixtures', function (): void {
    $unguardedJob = PolicySurface::sourceOf(UnguardedTenantJob::class);
    $guardedJob = PolicySurface::sourceOf(GuardedTenantJob::class);

    expect(PolicySurface::needsAbility($unguardedJob))->toBeTrue('a job naming a tenant model must be seen to reach tenant data')
        ->and(PolicySurface::authorizes($unguardedJob))->toBeFalse('the unguarded fixture must not look guarded')
        ->and(PolicySurface::needsAbility($guardedJob))->toBeTrue()
        ->and(PolicySurface::authorizes($guardedJob))->toBeTrue()
        ->and(PolicySurface::abilitiesNamedIn($guardedJob))->toContain(Abilities::UPDATE);

    expect(PolicySurface::isGatedTool(UngatedTool::class))->toBeFalse('the ungated fixture must not look gated')
        ->and(PolicySurface::isGatedTool(CustomerNotesTool::class))->toBeTrue();

    // The model-name signal is what carries the rule into Sprint 3, so assert
    // it actually contains the Sprint 2 models rather than an empty list — an
    // empty list would make `needsAbility()` answer false for everything.
    expect(PolicySurface::tenantModelNames())
        ->toContain('Customer', 'ToolRun', 'ActionProposal')
        ->and(count(PolicySurface::tenantModelNames()))->toBeGreaterThan(20);

    // And a source that merely mentions a model in prose is not a surface that
    // reaches one: the detector reads code, and `DB::table()` is the other half.
    expect(PolicySurface::touchesTenantData('<?php // nothing here'))->toBeFalse()
        ->and(PolicySurface::touchesTenantData('<?php DB::table("email_connections")->get();'))->toBeTrue();
})->group('security');

it('keeps the policy namespace free of anything that is not a policy', function (): void {
    foreach (glob(app_path('Policies/**/*.php')) ?: [] as $file) {
        expect(basename($file))->toEndWith('Policy.php', basename($file).' is in app/Policies but is not a policy');
    }

    foreach (PolicySurface::applicationClasses() as $class) {
        if (! str_starts_with($class, 'App\\Policies\\') || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        expect($class)->toEndWith('Policy')
            ->and(is_subclass_of($class, BusinessObjectPolicy::class))->toBeTrue("{$class} must extend BusinessObjectPolicy");
    }
})->group('security');

it('scopes every policy decision to a row rather than to a request field', function (): void {
    foreach (PolicySurface::applicationClasses() as $class) {
        if (! is_subclass_of($class, BusinessObjectPolicy::class)) {
            continue;
        }

        $source = PolicySurface::sourceOf($class);

        expect($source)->not->toContain('request()', "{$class} must not read the request: identity comes from the row and the session (ipc-contract §11.4)")
            ->and($source)->not->toContain('Request $', "{$class} must not take a Request");
    }

    $base = PolicySurface::sourceOf(BusinessObjectPolicy::class);

    expect($base)->not->toContain('request()')
        ->and($base)->toContain('Tenancy::isMember');
})->group('security');

it('reaches a Model through every policy, so a hydrated row is what is decided on', function (): void {
    foreach (PolicySurface::applicationClasses() as $class) {
        if (! is_subclass_of($class, BusinessObjectPolicy::class)) {
            continue;
        }

        $view = new ReflectionMethod($class, Abilities::VIEW);
        $parameters = $view->getParameters();

        expect($parameters)->toHaveCount(2, "{$class}::view() takes the user and the row");

        $type = $parameters[1]->getType();

        expect($type instanceof ReflectionNamedType && is_a($type->getName(), Model::class, true))
            ->toBeTrue("{$class}::view() must take an Eloquent model");
    }
})->group('security');
