<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The record that a brief was sent, with its idempotency key (§1.3). Evidence
 * of an outbound action, so append-only: a delivery that could be edited or
 * deleted would make a double send undetectable.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class BriefDeliveryPolicy extends BusinessObjectPolicy
{
    protected const bool APPEND_ONLY = true;
}
