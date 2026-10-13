<?php

declare(strict_types=1);

namespace Tests\Security\Support\Surfaces;

use App\Models\CRM\Customer;
use App\Security\Authorization\Gates\AuthorizesQueuedWork;
use App\Security\Authorization\Maintenance\TenantWideMaintenance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * The member #127 exists to stop: it carries `TenantWideMaintenance` and then
 * does every single thing the category forbids — it is a `ShouldQueue` job a
 * user could dispatch, it authorises as a user, it reads the authenticated
 * user, it takes a `Request`-shaped identity from its caller, and it names
 * itself something other than its class.
 *
 * It is here on purpose, exactly like `UnguardedTenantJob`:
 * `MaintenanceCategoryArchTest` runs its rules over this class and asserts each
 * one flags it. A rule that has never seen a violation is not known to
 * recognise one, and the whole value of this category is in the rules rather
 * than in the marker.
 *
 * It is never dispatched, never registered, and lives under `tests/`, which is
 * also why the *enumeration* rule does not see it: that rule reads `app/`,
 * because the category is a property of the application and a fixture that
 * joined the list would make the list a fiction. The planted-class proof for
 * the enumeration is done in `app/` by hand and recorded in the PR.
 */
final class UnboundedMaintenancePass implements ShouldQueue, TenantWideMaintenance
{
    use AuthorizesQueuedWork;
    use Queueable;

    public function __construct(private readonly string $actorUserId) {}

    public function maintenanceOperation(): string
    {
        return 'user:01M4JGMWH02GAABCPY27KT2DQK';
    }

    public function handle(): void
    {
        $customer = Customer::query()->firstOrFail();

        $this->authorizeJob($this->actorUserId, 'update', $customer);

        Auth::user();
        request()->input('organization_id');

        $customer->forceFill(['notes' => 'reached on behalf of a user'])->save();
    }
}
