<?php

declare(strict_types=1);

namespace App\Security\Retention;

/**
 * What `PurgeExpiredData` (§4.1) does when a row's clock runs out.
 *
 * `KeepUntilErased` is the one that has to be *declared*: it means the rule has no
 * clock at all, and the row leaves only through a user deletion or a GDPR erasure.
 * `RetentionRule` enforces the equivalence `retainDays === null ⟺ KeepUntilErased`,
 * so a rule can never end up without a clock by omission — which is exactly the
 * "row that silently never expires" S2-03 exists to prevent.
 */
enum PurgeAction: string
{
    case Purge = 'purge';

    case Anonymize = 'anonymize';

    case KeepUntilErased = 'keep_until_erased';
}
