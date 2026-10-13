<?php

declare(strict_types=1);

namespace App\Policies\CRM;

use App\Policies\BusinessObjectPolicy;

/**
 * The raw provenance of an ingested row: which connector, which external id,
 * which cursor. Append-only — it is the evidence of where a CRM row came from,
 * and a rewritable provenance record is not provenance.
 *
 * The tenant rule, the append-only rule and the "no admin bypass" rule all
 * live in `BusinessObjectPolicy`; this class only names the table they apply
 * to, so there is one policy per §3.1 business object and
 * `PolicySurfaceArchTest` can see it.
 *
 * @see BusinessObjectPolicy
 */
final class SourceRecordPolicy extends BusinessObjectPolicy
{
    protected const bool APPEND_ONLY = true;
}
