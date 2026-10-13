<?php

declare(strict_types=1);

use App\Security\Authorization\Gates\AuthorizesQueuedWork;
use App\Security\Authorization\Gates\GatedTool;
use App\Security\Authorization\Maintenance\MaintenanceActor;
use App\Security\Authorization\Maintenance\TenantWideMaintenance;
use App\Security\Secrets\AppKeyRotation;
use App\Security\Secrets\EncryptedAttributeInventory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Tests\Security\Support\PolicySurface;
use Tests\Security\Support\Surfaces\UnboundedMaintenancePass;

/*
|--------------------------------------------------------------------------
| The bounded exception (#127): tenant-wide maintenance
|--------------------------------------------------------------------------
|
| S2-06 made one rule structural — every surface that reaches tenant data runs
| as a **user**, with that user's memberships, and there is no privileged job
| identity to escalate to (`AuthorizesQueuedWork`, threat model T-73). Three
| operations cannot satisfy that rule and are not defects: the app-key
| re-encryption pass (ADR-003 D8), the retention purge (S2-03) and the backup
| job (ADR-002 D5). All three are authorised by **not being a user action**.
|
| That is a real category. Before this file it was an unnamed exception, which
| is how the next author justifies the privileged job identity S2-06 exists to
| prevent. So the category has a marker (`TenantWideMaintenance`), and this file
| is what makes membership a decision rather than a convenience.
|
| Five rules, each closing a different hole:
|
|  1. **Every member is on a list, with a reason.** A class under `app/` that
|     carries the marker and is absent from `TENANT_WIDE_MAINTENANCE` fails the
|     build until somebody adds it with a sentence saying why it has no user
|     principal. Growing the category costs a reviewed decision, not an import.
|     This is the same shape as `IpcPolicyArchTest`'s pre-owner set (exactly one
|     route) and `NoSocketTransportArchTest`'s `EGRESS_CALL_SITES` (a file → a
|     reason). The *table of reasons* is the half worth copying: a list of class
|     names tells a reviewer what is exempt, and a list of reasons tells them
|     whether the next entry belongs.
|  2. **A member is not itself a user-facing surface.** Not a `ShouldQueue` job,
|     not a `GatedTool`, not a Livewire component, and it does not carry
|     `AuthorizesQueuedWork`.
|  3. **Nothing user-facing reaches a member.** No route handler, Livewire
|     component, tool or queued job may name one — read from the code with
|     comments stripped, so the many docblocks that discuss `AppKeyRotation` are
|     not mistaken for call sites.
|  4. **A member takes no identity.** No `auth()`, no `request()`, no
|     `Gate::forUser()`, no `User` or `Request` parameter. This is the rule that
|     stops the category becoming what it would otherwise become: a way to reach
|     tenant data *on behalf of a user*, with install-wide scope and no policy.
|  5. **The trail says the system did it.** A member's audit actor comes from
|     `MaintenanceActor`, so it is `maintenance:<Operation>` and not `job:…` —
|     see that class on why `job:` is not good enough — and a member may not
|     write a `user:` actor at all.
|
| Rules 2–5 are proved against `Surfaces\UnboundedMaintenancePass`, a fixture
| that carries the marker and breaks all of them, in the last `it()` below: a
| rule that has never seen a violation is not known to recognise one. Rule 1 is
| proved by planting a class in `app/` by hand, which is recorded in the PR
| rather than automated, because a fixture that joined the list would make the
| list a fiction.
|
*/

/**
 * **The category, and why each member has no user principal.**
 *
 * Adding a row is a security-critical decision: it says this operation reaches
 * every tenant's rows and answers to no policy, because there is no principal
 * for a policy to be about. The reason must say *why no user can own it* — not
 * what the operation does.
 *
 * Not on this list and not to be added by retrofit: the **retention purge**
 * (S2-03) and the **backup job** (ADR-002 D5). Neither is written yet. Their
 * authors implement `TenantWideMaintenance`, add the row with the reason, write
 * the audit row through `MaintenanceActor::for($this)`, and drive the operation
 * from the scheduler or an artisan command — never from a route, a Livewire
 * action or a job a user dispatches.
 *
 * @var array<class-string<TenantWideMaintenance>, string>
 */
const TENANT_WIDE_MAINTENANCE = [
    AppKeyRotation::class => 'ADR-003 D8: `core.app-key` is install-wide, so no dispatcher\'s memberships describe what the rotation covers, and four of the eight tables it must rewrite — ai_runs, tool_runs, source_records, memory_sources — are append-only by policy, so `update` is false for them to every user by design',
];

/** Namespaces whose executable classes are tools (the `TOOL_NAMESPACES` of PolicySurfaceArchTest, under a name this file can own). */
const MAINTENANCE_TOOL_NAMESPACES = ['App\\AI\\Tools\\', 'App\\MCP\\'];

/**
 * How a member would take an identity it is not allowed to have. Each is a
 * thing you cannot do without writing it down.
 *
 * @var array<string, string>
 */
const MAINTENANCE_IDENTITY_SIGNALS = [
    'reads the authenticated user' => '/\b(Auth::|auth\(\)|->user\(\))/',
    'reads the request' => '/\brequest\(\)|Request::/',
    'runs a gate for a user' => '/\bGate::|forUser\s*\(|->authorize\w*\s*\(/',
    'writes a user-shaped audit actor' => '/[\'"]user:/',
];

/** @return list<class-string<TenantWideMaintenance>> every class under app/ carrying the marker */
function maintenanceMembers(): array
{
    /** @var list<class-string<TenantWideMaintenance>> $members */
    $members = PolicySurface::classesImplementing(TenantWideMaintenance::class);

    sort($members);

    return $members;
}

/**
 * A class's PHP code with comments and docblocks removed. The category is
 * discussed in prose in a dozen files — `SensitiveAttributeRewriter`,
 * `EncryptedAttributeInventory`, `ContactEmail` all name `AppKeyRotation` in a
 * docblock — and a rule that read those as call sites would be noise that
 * teaches people to edit the allowlist without thinking.
 *
 * @param  class-string  $class
 */
function maintenanceCodeOf(string $class): string
{
    $code = '';

    foreach (token_get_all(PolicySurface::sourceOf($class)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/**
 * Every class a user can reach: a route's controller, a Livewire component, a
 * tool, a queued job. Reused from `PolicySurface` rather than re-derived — it
 * is the same reading `PolicySurfaceArchTest` does, and two sets of detectors
 * would be two sets to keep honest.
 *
 * @return array<string, class-string> a label a failure can name => the class
 */
function userFacingClasses(): array
{
    $classes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $controller = $route->getControllerClass();

        if (is_string($controller) && str_starts_with($controller, 'App\\') && class_exists($controller)) {
            $classes["the route handler {$route->methods()[0]} {$route->uri()}"] = $controller;
        }
    }

    foreach (PolicySurface::classesImplementing(Component::class) as $component) {
        $classes["the Livewire component {$component}"] = $component;
    }

    foreach (PolicySurface::toolClasses(MAINTENANCE_TOOL_NAMESPACES) as $tool) {
        $classes["the tool {$tool}"] = $tool;
    }

    foreach (PolicySurface::queuedJobs() as $job) {
        $classes["the queued job {$job}"] = $job;
    }

    return $classes;
}

/**
 * Whether a source names a class at all — by FQCN or by basename, because an
 * `use` plus a short name is how it is actually written.
 */
function namesClass(string $code, string $class): bool
{
    return str_contains($code, $class) || preg_match('/\b'.preg_quote(class_basename($class), '/').'\b/', $code) === 1;
}

it('enumerates every class carrying the marker, so growing the category costs a decision', function (): void {
    $found = maintenanceMembers();
    $listed = array_keys(TENANT_WIDE_MAINTENANCE);

    $unlisted = array_values(array_diff($found, $listed));
    $stale = array_values(array_diff($listed, $found));

    expect($unlisted)->toBe([], implode("\n", array_map(
        static fn (string $class): string => "{$class} implements TenantWideMaintenance but is not in TENANT_WIDE_MAINTENANCE. "
            .'A member reaches every tenant\'s rows and answers to no policy, so it is a reviewed decision: add it to the table '
            .'with a sentence saying why it has no user principal, and read what the category may not do in '
            .'docs/architecture/authorization.md §10 before you do.',
        $unlisted,
    )));

    expect($stale)->toBe([], 'TENANT_WIDE_MAINTENANCE names a class that no longer carries the marker; remove the row rather than leaving a standing exemption');
})->group('security');

it('makes every member state why it has no user principal, in a sentence', function (): void {
    foreach (TENANT_WIDE_MAINTENANCE as $class => $reason) {
        // A reason is what a reviewer reads when the next entry is proposed. A
        // class name with "maintenance" after it is not one.
        expect(strlen($reason))->toBeGreaterThan(60, "{$class}: the reason must say why no user can own this operation")
            ->and(str_contains($reason, class_basename($class)))->toBeFalse("{$class}: the reason must not just repeat the class name");
    }
})->group('security');

it('derives each member\'s audit actor from the marker, with no room for a typo', function (): void {
    $operations = [];

    foreach (array_keys(TENANT_WIDE_MAINTENANCE) as $class) {
        $member = app($class);

        expect($member)->toBeInstanceOf(TenantWideMaintenance::class);

        $operation = $member->maintenanceOperation();

        // Held to the class basename so the trail cannot drift from the code,
        // and unique so two members cannot be one row in the trail.
        expect($operation)->toBe(class_basename($class), "{$class} must name itself by its class")
            ->and(in_array($operation, $operations, true))->toBeFalse("{$operation} is claimed by two members")
            ->and(MaintenanceActor::for($member))->toBe(MaintenanceActor::PREFIX.class_basename($class));

        $operations[] = $operation;
    }
})->group('security');

it('refuses an operation name that could read as a person', function (): void {
    // The one column that distinguishes the system from a user must not be
    // writable by the member whose honesty it records.
    expect(fn (): string => MaintenanceActor::named('user:01M4JGMWH02GAABCPY27KT2DQK'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => MaintenanceActor::named('maintenance:Nested'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => MaintenanceActor::named(''))->toThrow(InvalidArgumentException::class);

    expect(MaintenanceActor::isMaintenance('maintenance:AppKeyRotation'))->toBeTrue()
        ->and(MaintenanceActor::isMaintenance('job:AppKeyRotation'))->toBeFalse()
        ->and(MaintenanceActor::isMaintenance('maintenance:user:01M4JGMWH02GAABCPY27KT2DQK'))->toBeFalse()
        ->and(MaintenanceActor::operationOf('maintenance:AppKeyRotation'))->toBe('AppKeyRotation')
        ->and(MaintenanceActor::operationOf('user:01M4JGMWH02GAABCPY27KT2DQK'))->toBeNull();
})->group('security');

it('keeps a member from being a user-facing surface itself', function (): void {
    foreach (maintenanceMembers() as $class) {
        expect(is_subclass_of($class, ShouldQueue::class))->toBeFalse(
            "{$class} is a TenantWideMaintenance member and a ShouldQueue job: a job is dispatched, and whoever dispatches it is the user principal the category says it does not have",
        )
            ->and(PolicySurface::isGatedTool($class))->toBeFalse("{$class} must not be a GatedTool: a tool runs with the dispatching user's memberships")
            ->and(is_subclass_of($class, Component::class))->toBeFalse("{$class} must not be a Livewire component")
            ->and(in_array(AuthorizesQueuedWork::class, class_uses_recursive($class), true))->toBeFalse(
                "{$class} carries AuthorizesQueuedWork, which exists to run work as its dispatching user — the opposite of this category",
            )
            ->and(PolicySurface::toolClasses(MAINTENANCE_TOOL_NAMESPACES))->not->toContain($class);
    }
})->group('security');

it('lets no route, Livewire action, tool or queued job reach a member', function (): void {
    $offenders = [];

    foreach (userFacingClasses() as $label => $surface) {
        $code = maintenanceCodeOf($surface);

        foreach (maintenanceMembers() as $member) {
            if (namesClass($code, $member)) {
                $offenders[] = "{$label} names {$member}";
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        ...$offenders,
        'A tenant-wide maintenance pass reaches every organization\'s rows and answers to no policy, so a user-facing '
        .'surface that can invoke one is a user reaching every tenant\'s data without a check. A surface may *request* '
        .'maintenance — write the envelope, set a flag, record the request — and the pass runs from the scheduler or an '
        .'artisan command. See docs/architecture/authorization.md §10.',
    ]));
})->group('security');

it('lets a member take no identity from a user or a request', function (): void {
    foreach (maintenanceMembers() as $class) {
        $code = maintenanceCodeOf($class);

        foreach (MAINTENANCE_IDENTITY_SIGNALS as $what => $pattern) {
            expect(preg_match($pattern, $code))->toBe(
                0,
                "{$class} {$what}. A member that needs to know who asked is a user action and belongs behind a policy; "
                .'this category is authorised by there being no principal, not by outranking one.',
            );
        }

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                expect(is_a($type->getName(), Authenticatable::class, true))->toBeFalse(
                    "{$class}::{$method->getName()} accepts a user; a member acts for nobody",
                )->and(is_a($type->getName(), Request::class, true))->toBeFalse(
                    "{$class}::{$method->getName()} accepts a Request; identity never comes from request content (ipc-contract §11.4)",
                );
            }
        }
    }
})->group('security');

it('writes every member\'s audit actor through MaintenanceActor', function (): void {
    foreach (maintenanceMembers() as $class) {
        $code = maintenanceCodeOf($class);

        expect(str_contains($code, 'MaintenanceActor::'))->toBeTrue(
            "{$class} must name its audit actor through MaintenanceActor, so the trail distinguishes \"the system did this with nobody asking\" from \"a user did this\"",
        )->and(preg_match('/[\'"]job:/', $code))->toBe(
            0,
            "{$class} writes a `job:` actor, which this repository also writes for pre-identity *user* actions (job:CreateOwnerForm); use MaintenanceActor::for(\$this)",
        );
    }
})->group('security');

it('states in the documents what the category is and what it may not do', function (): void {
    // The marker is only half the mechanism: a reviewer deciding on the next
    // member reads the page, so the page must exist and must still be the page
    // that says no.
    $authorization = (string) file_get_contents(base_path('docs/architecture/authorization.md'));
    $keychain = (string) file_get_contents(base_path('docs/adrs/003-keychain-and-secrets.md'));
    $database = (string) file_get_contents(base_path('docs/adrs/002-local-db-and-queue.md'));

    expect($authorization)->toContain('TenantWideMaintenance')
        ->and($authorization)->toContain('MaintenanceActor')
        ->and($authorization)->toContain('not a way to reach tenant data on behalf of a user')
        ->and($keychain)->toContain('TenantWideMaintenance')
        // ADR-003 D8 said the re-encryption runs "in a queued job", which S2-06
        // made impossible; #127 corrects the wording rather than leaving the ADR
        // contradicting the code.
        ->and($keychain)->not->toContain('in a queued job')
        ->and($database)->toContain('TenantWideMaintenance');
})->group('security');

it('recognises a member that breaks the rules — the rules, proved against a fixture', function (): void {
    // `Surfaces\UnboundedMaintenancePass` carries the marker and then does
    // everything the category forbids. Each expectation below is one of the
    // rules above, run against it, so none of them is a rule that has never
    // seen a violation.
    $fixture = UnboundedMaintenancePass::class;
    $code = maintenanceCodeOf($fixture);

    // Rule 2 is about two interfaces co-occurring, so the fixture is read for the
    // contradiction itself: it is a member *and* a queued job, which is the shape
    // the rule refuses. (Read through `class_implements` rather than asserted
    // about a literal class name — the type checker already proves the literal,
    // and an assertion it can answer statically is not a test.)
    $interfaces = array_keys((array) class_implements($fixture));

    expect(in_array(TenantWideMaintenance::class, $interfaces, true))->toBeTrue('the fixture must carry the marker, or it proves nothing')
        ->and(in_array(ShouldQueue::class, $interfaces, true))->toBeTrue('rule 2: the fixture must be the forbidden member-and-job at once')
        ->and(in_array(AuthorizesQueuedWork::class, class_uses_recursive($fixture), true))->toBeTrue('rule 2: AuthorizesQueuedWork is flagged');

    foreach (MAINTENANCE_IDENTITY_SIGNALS as $what => $pattern) {
        expect(preg_match($pattern, $code))->toBe(1, "rule 4 must see that the fixture {$what}");
    }

    expect(str_contains($code, 'MaintenanceActor::'))->toBeFalse('rule 5: a member that never names MaintenanceActor is flagged')
        ->and(fn (): string => MaintenanceActor::for(app($fixture, ['actorUserId' => '01M4JGMWH02GAABCPY27KT2DQK'])))
        ->toThrow(InvalidArgumentException::class, null, 'rule 5: an operation that names itself a user is refused');

    // Rule 3 in both directions: a source that names a member is seen, and one
    // that merely discusses it in a docblock is not — which is the distinction
    // that keeps this rule usable, since a dozen files name AppKeyRotation in
    // prose.
    expect(namesClass('<?php (new AppKeyRotation)->handle();', AppKeyRotation::class))->toBeTrue()
        ->and(namesClass(maintenanceCodeOf(EncryptedAttributeInventory::class), AppKeyRotation::class))->toBeFalse(
            'a docblock that discusses AppKeyRotation is not a call site',
        );

    // And the enumeration's boundary, stated rather than assumed: the rule reads
    // `app/`, so this fixture is deliberately not a member of the real list.
    expect(maintenanceMembers())->not->toContain($fixture)
        ->and(maintenanceMembers())->toBe([AppKeyRotation::class]);
})->group('security');
