<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use App\Http\Middleware\EnsureNoOwnerExists;
use App\Security\Authorization\Gates\Abilities;
use App\Security\Authorization\Gates\GatedTool;
use App\Security\Authorization\OwnerAbilities;
use FilesystemIterator;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * The reader behind `PolicySurfaceArchTest` (S2-06, blueprint §11).
 *
 * `IpcPolicyArchTest` proved the shape on one surface by reading the routes
 * and the controller sources. This generalises it: the same reading, applied
 * to every route, every Livewire component, every queued job and every tool
 * class, with one rule that decides *which* of them need an ability —
 *
 *   **a surface that reaches tenant data must name an ability.**
 *
 * "Reaches tenant data" is read from the source, not from a list: a reference
 * to a model whose table carries `organization_id`, a `DB::table()` call or an
 * Eloquent query. That is what makes the test survive the next sprint. A job
 * added in Sprint 3 that queries `customers` is caught because it mentions
 * `Customer`, not because anybody remembered to register it.
 *
 * The detectors are separated from the test so the test can prove they work:
 * `PolicySurfaceArchTest` runs them over the deliberately unguarded fixtures in
 * `Tests\Security\Support\Surfaces` and asserts they are flagged. A detector
 * nobody has tested is not an enforcement mechanism, it is a hope.
 */
final class PolicySurface
{
    /**
     * Middleware that structurally closes a surface to signed-in users, which
     * is why the guest components (`Login`, `CreateOwnerForm`) have no ability:
     * there is no user to authorize, and the guard is what keeps them
     * unreachable once there is one.
     *
     * @var list<class-string>
     */
    public const array GUEST_GUARDS = [
        RedirectIfAuthenticated::class,
        EnsureNoOwnerExists::class,
    ];

    /** Methods Livewire itself calls; everything else a component declares is an action. */
    public const array LIVEWIRE_LIFECYCLE = [
        '__construct', 'mount', 'boot', 'booted', 'hydrate', 'dehydrate', 'rendering', 'rendered',
        'updating', 'updated', 'exception', 'placeholder',
    ];

    /** A class in a tool namespace with one of these is executable, so it must be gated. */
    public const array TOOL_ENTRY_POINTS = ['handle', '__invoke', 'run', 'execute', 'call'];

    /** @var list<string>|null */
    private static ?array $tenantModelNames = null;

    /**
     * Whether a source file reaches tenant data at all. Three signals, all of
     * them things you cannot do without writing them down:
     *
     * - naming a model whose table carries `organization_id`;
     * - `DB::table(...)` / `DB::select(...)`, the way a table without a model
     *   is read;
     * - an Eloquent query builder on anything.
     */
    public static function touchesTenantData(string $source): bool
    {
        foreach (self::tenantModelNames() as $model) {
            if (preg_match('/\b'.preg_quote($model, '/').'\b/', $source) === 1) {
                return true;
            }
        }

        return preg_match('/\bDB::(table|select|update|insert|delete|statement)\s*\(/', $source) === 1;
    }

    /**
     * The abilities a source names, as their *values*. A constant reference
     * (`Abilities::VIEW`) and a literal (`'view'`) both count: the constant is
     * the convention, the literal is what a careless addition looks like, and
     * for the purpose of "did this surface run a check" they are the same.
     *
     * @return list<string>
     */
    public static function abilitiesNamedIn(string $source): array
    {
        $abilities = [];

        foreach (self::abilityConstants() as $reference => $value) {
            if (str_contains($source, $reference)) {
                $abilities[] = $value;
            }
        }

        preg_match_all('/authorize\w*\(\s*\'([A-Za-z][A-Za-z0-9_]*)\'/', $source, $literals);

        foreach ($literals[1] as $literal) {
            $abilities[] = (string) $literal;
        }

        return array_values(array_unique($abilities));
    }

    /**
     * Whether the source runs an authorization check at all — the call, not
     * just the ability name, so a docblock mentioning `view` is not mistaken
     * for a check.
     */
    public static function authorizes(string $source): bool
    {
        return preg_match('/(Gate::|->|\$this->)(authorize|authorizeJob|allows|denies|check|raw)\s*\(/', $source) === 1;
    }

    /** A surface needs an ability exactly when it reaches tenant data. */
    public static function needsAbility(string $source): bool
    {
        return self::touchesTenantData($source);
    }

    /**
     * The names of the models whose tables carry `organization_id`, short form
     * (`Customer`, `AiRun`), because that is how a source refers to them.
     *
     * @return list<string>
     */
    public static function tenantModelNames(): array
    {
        if (self::$tenantModelNames !== null) {
            return self::$tenantModelNames;
        }

        $tenantTables = TenantRowFactory::tenantTables();
        $names = [];

        foreach (TenantRowFactory::modelsByTable() as $table => $class) {
            if (in_array($table, $tenantTables, true)) {
                $names[] = class_basename($class);
            }
        }

        sort($names);

        return self::$tenantModelNames = $names;
    }

    /**
     * Every ability constant in the application, as reference => value, so a
     * source can be checked for either spelling.
     *
     * @return array<string, string>
     */
    public static function abilityConstants(): array
    {
        $constants = [];

        foreach ([Abilities::class, OwnerAbilities::class] as $class) {
            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                if (is_string($value)) {
                    $constants[class_basename($class).'::'.$name] = $value;
                }
            }
        }

        return $constants;
    }

    /**
     * Classes under `app/` that implement `$interface` (or extend `$parent`).
     *
     * @param  class-string  $contract
     * @return list<class-string>
     */
    public static function classesImplementing(string $contract): array
    {
        $found = [];

        foreach (self::applicationClasses() as $class) {
            if (! is_subclass_of($class, $contract)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $found[] = $class;
        }

        return $found;
    }

    /** @return list<class-string> every queued job in the application */
    public static function queuedJobs(): array
    {
        return self::classesImplementing(ShouldQueue::class);
    }

    /**
     * Executable classes in a tool namespace: the ones that must implement
     * `GatedTool`. Enums and value objects in those namespaces are not tools.
     *
     * @param  list<string>  $namespaces
     * @return list<class-string>
     */
    public static function toolClasses(array $namespaces): array
    {
        $tools = [];

        foreach (self::applicationClasses() as $class) {
            if (! self::inAnyNamespace($class, $namespaces)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isEnum() || $reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            foreach (self::TOOL_ENTRY_POINTS as $entryPoint) {
                if ($reflection->hasMethod($entryPoint) && $reflection->getMethod($entryPoint)->isPublic()) {
                    $tools[] = $class;

                    break;
                }
            }
        }

        return $tools;
    }

    public static function isGatedTool(string $class): bool
    {
        return is_subclass_of($class, GatedTool::class);
    }

    /**
     * The public methods a Livewire component declares itself, minus the
     * lifecycle hooks — in other words, the actions a browser can call.
     *
     * @param  class-string  $component
     * @return list<ReflectionMethod>
     */
    public static function livewireActions(string $component): array
    {
        $actions = [];

        foreach ((new ReflectionClass($component))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $component) {
                continue;
            }

            if (in_array($method->getName(), self::LIVEWIRE_LIFECYCLE, true) || $method->isStatic()) {
                continue;
            }

            $actions[] = $method;
        }

        return $actions;
    }

    /** The lines of one method, so an action can be read on its own. */
    public static function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();

        $lines = array_slice((array) file($file), $start - 1, $end - $start + 1);

        return implode('', $lines);
    }

    /** @param class-string $class */
    public static function sourceOf(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }

    /**
     * Every class under `app/`, by filename convention. The application is
     * PSR-4 under `App\`, so the path is the name.
     *
     * @return list<class-string>
     */
    public static function applicationClasses(): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            /** @var class-string $class */
            $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', substr($file->getPathname(), strlen(app_path()) + 1, -4));

            if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /** @return list<class-string<Model>> */
    public static function modelClasses(): array
    {
        return TenantRowFactory::modelClasses();
    }

    /** @param list<string> $namespaces */
    private static function inAnyNamespace(string $class, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
