<?php

declare(strict_types=1);

namespace Tests\Security\Support\Surfaces;

use App\Models\CRM\Customer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The job S2-06 exists to stop: it reaches a tenant row by id and never runs a
 * check, so a customer id from another organization would be updated as
 * happily as one of the actor's own (threat model T-73, "a queued job").
 *
 * It is here on purpose. `PolicySurfaceArchTest` runs its detectors over this
 * class and asserts they flag it — a detector that has never seen an
 * unguarded surface is not known to recognise one. It is never dispatched, and
 * it is in the test suite, so nothing in the application can reach it.
 */
final class UnguardedTenantJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $customerId) {}

    public function handle(): void
    {
        Customer::query()->findOrFail($this->customerId)->forceFill(['notes' => 'no check ran'])->save();
    }
}
