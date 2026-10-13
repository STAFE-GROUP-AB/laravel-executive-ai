<?php

declare(strict_types=1);

namespace App\Policies\Approvals;

use App\Policies\BusinessObjectPolicy;

/**
 * A frozen, immutable proposal for an outbound action (§11, ADR-005 D1.6).
 * The model refuses a write to a frozen column; the policy refuses the `update`
 * before the write, so the refusal is a 403 with an audit row rather than an
 * exception inside a save. Approving is not `update`: it is a separate, re-
 * authenticated action in the PWA and never over the IPC (ipc-contract §11.2).
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class ActionProposalPolicy extends BusinessObjectPolicy
{
    protected const bool IMMUTABLE_AFTER_FREEZE = true;
}
