<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * A brief is visible to the members of the organization it belongs to and to
 * nobody else (§11 "Laravel Policies on every read"; §5.6 acceptance
 * criterion 4).
 *
 * S1-03 wrote this rule by hand, as the first policy in the application and
 * the precedent the rest of §11 was going to follow. S2-06 made it the
 * shared rule: `BusinessObjectPolicy` is the same membership check, applied to
 * every §3.1 table, and this class is what is left of the original once the
 * duplication was removed. The behaviour it had is unchanged — a brief outside
 * the user's organizations is a 403 on read, the version list is
 * `BriefCatalog::visibleTo()`, and there is no admin bypass.
 *
 * A brief version is never edited or deleted through a surface: it is frozen
 * at generation, with `content_hash` over its canonical form, and the
 * retention job is what removes it. The base policy's append-only switch is
 * deliberately *not* set, because a brief is not evidence of an action the way
 * `brief_deliveries` is — S3's "regenerate" lands here as a `create` of a new
 * version, not an `update` of this one.
 *
 * @see BusinessObjectPolicy
 */
final class BriefPolicy extends BusinessObjectPolicy {}
