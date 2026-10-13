<?php

declare(strict_types=1);

namespace Tests\Security\Support\Surfaces;

use App\Models\CRM\Customer;

/**
 * An executable tool that does not implement `GatedTool`, so nothing decides
 * whether the model may touch the row it names. The counterpart of
 * `UnguardedTenantJob` for the tool surface: `PolicySurfaceArchTest` asserts
 * its detector flags this class, which is what gives the rule over
 * `app/AI/Tools/` and `app/MCP/` its meaning.
 */
final class UngatedTool
{
    /** @param array<string, mixed> $arguments */
    public function handle(array $arguments): string
    {
        $customer = Customer::query()->find($arguments['customer_id'] ?? '');

        return (string) $customer?->getAttribute('display_name');
    }
}
