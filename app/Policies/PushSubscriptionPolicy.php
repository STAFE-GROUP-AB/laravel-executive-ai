<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * One browser's Web Push subscription (ADR-006 through ADR-002 D9.5). The row
 * belongs to the organization whose `organization_id` it carries and to nobody
 * else, which is the rule `BusinessObjectPolicy` already states.
 *
 * **Not append-only**, unlike the evidence tables: a live subscription is
 * updated in the ordinary course of delivering to it — `last_seen`,
 * `last_delivery_at`, `failure_count` — and it is *revoked* by stamping
 * `revoked_at` when the push service reports it gone (404/410) or the person
 * turns notifications off. Deleting it is a legitimate user action too ("stop
 * sending to this device"), and it is a soft delete, so the row's §3.1
 * retention evidence survives it; `forceDelete` is refused here as everywhere.
 *
 * This policy exists because S2-04 (#87) gave `push_subscriptions` its model —
 * ADR-002 D9.5 and that table's migration assign the `encrypted:` cast on
 * `p256dh`/`auth` to this item by name — and the moment a tenant table has a
 * model it needs a policy: S2-06's schema-driven sweep (`tests/Security/IdorTest.php`,
 * `tests/Integration/BackendContract/CrossAccountContractTest.php`) takes the
 * "no model, no policy needed yet" branch only while no model exists. That
 * sweep is what caught the gap, which is exactly what it is for.
 *
 * The tenant rule and the "no admin bypass" rule both live in
 * `BusinessObjectPolicy`; this class only names the table they apply to, so
 * there is one policy per business object and `PolicySurfaceArchTest` can see
 * it.
 *
 * @see BusinessObjectPolicy
 */
final class PushSubscriptionPolicy extends BusinessObjectPolicy {}
