<?php

declare(strict_types=1);

namespace App\Providers;

use App\Security\Authorization\Gates\AuthorizationAuditor;
use App\Security\Authorization\Gates\SurfaceContext;
use App\Security\Authorization\Gates\SurfaceGates;
use App\Security\Authorization\Gates\TenantRowGuard;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * S2-06: the authorization layer's one wiring point.
 *
 * Policies are **not** registered here. Laravel discovers
 * `App\Models\CRM\Customer` → `App\Policies\CRM\CustomerPolicy` by convention,
 * and a convention cannot go stale the way a registration array does: adding a
 * model and forgetting a line in a provider is precisely the gap S2-06 exists
 * to close. `PolicySurfaceArchTest` asserts the discovery actually resolves,
 * per table, from the schema.
 *
 * What is registered here:
 *
 * 1. the `SurfaceGates` abilities (the PWA pages, the Livewire actions, the
 *    tenant gate for a table with no model yet);
 * 2. the Gate `after` hook that writes the audit row for every refusal and
 *    every mutating allow (`AuthorizationAuditor`, threat model T-46).
 *
 * `IpcServiceProvider` registers `OwnerAbilities` for the §6 IPC operations;
 * the two sets are disjoint and both run through the same hook, so an IPC
 * refusal and a PWA refusal leave the same shape of row with a different
 * `surface`.
 *
 * Note the deliberate absence of `Gate::before()`. A `before` hook is the one
 * mechanism that can answer true for a row outside the user's memberships, so
 * the "no admin bypass" rule is enforceable only while there is none —
 * `PolicySurfaceArchTest` asserts the Gate has no `before` callbacks at all.
 */
final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SurfaceContext::class);
        $this->app->singleton(AuthorizationAuditor::class);

        $this->app->bind(TenantRowGuard::class, fn (Application $app): TenantRowGuard => new TenantRowGuard(
            $app->make(GateContract::class),
            $app->make('db.connection'),
            $app->make(SurfaceContext::class),
        ));
    }

    public function boot(): void
    {
        SurfaceGates::define($this->app->make(GateContract::class));

        $this->auditEveryDecision();
    }

    /**
     * The hook observes; it must never decide. `callAfterCallbacks()` only
     * adopts an `after` callback's return value when the result so far is
     * null, so returning null here leaves every decision exactly as the
     * policy or gate made it.
     *
     * The first parameter is nullable on purpose: Laravel skips an `after`
     * callback that cannot be called with a null user, and a guest's refusal
     * is one of the refusals T-46 wants on the record.
     */
    private function auditEveryDecision(): void
    {
        $auditor = $this->app->make(AuthorizationAuditor::class);

        Gate::after(static function (?Authenticatable $user, string $ability, bool|Response|null $result, array $arguments) use ($auditor): null {
            $auditor->record($user, $ability, $result, $arguments);

            return null;
        });
    }
}
