<?php

declare(strict_types=1);

namespace Tests\Security\Support\Surfaces;

use App\Models\CRM\Customer;
use App\Security\Authorization\Gates\Abilities;
use App\Security\Authorization\Gates\AuthorizesQueuedWork;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A queued job that reaches tenant data and authorizes first — the shape
 * `PolicySurfaceArchTest` requires of every such job, and the job
 * `AuditCompletenessTest` runs to prove the `job` surface leaves an audit row
 * (threat model T-73's fourth path).
 *
 * It lives in the test suite rather than in `app/` because the application has
 * no tenant-touching job yet. The point is not what it does; it is that the
 * authorization seam a real job will use is exercised by a real job today,
 * rather than being a trait nobody has called.
 */
final class GuardedTenantJob implements ShouldQueue
{
    use AuthorizesQueuedWork;
    use Queueable;

    public function __construct(
        private readonly string $actorUserId,
        private readonly string $customerId,
    ) {}

    public function handle(): void
    {
        $customer = Customer::query()->findOrFail($this->customerId);

        $this->authorizeJob($this->actorUserId, Abilities::UPDATE, $customer);

        $customer->forceFill(['notes' => 'touched by a job'])->save();
    }
}
