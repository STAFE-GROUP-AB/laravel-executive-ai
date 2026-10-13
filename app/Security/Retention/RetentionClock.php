<?php

declare(strict_types=1);

namespace App\Security\Retention;

/**
 * Which column starts a rule's countdown. The purge job reads `column()`; the
 * governance schema contract test asserts that every table a rule applies to
 * actually has that column, so a rule whose clock does not exist on its own tables
 * fails before it can quietly hold data forever.
 *
 * **`expires_at` is deliberately not a case, and must never become one.** Several
 * tables carry one and in every case it is a *business* deadline, not a purge
 * deadline: the §11 approval window on `action_proposals`/`action_approvals`, the
 * ADR-005 D1.8 `browser_sessions` grant, ADR-004 D5's evidence lapse on
 * `ai_providers`, when a `memories` row stops being active, when a push endpoint
 * stops being valid. An approval that lapsed an hour ago is exactly the row that
 * proves who consented to what. `retain_until`, derived from one of the clocks
 * below, is the only column that means "the purge job may delete this row"
 * (`RetentionRuleResolutionTest` pins this).
 */
enum RetentionClock: string
{
    case CreatedAt = 'created_at';

    case UpdatedAt = 'updated_at';

    case DeletedAt = 'deleted_at';

    case OccurredAt = 'occurred_at';

    case LastSeen = 'last_seen';

    public function column(): string
    {
        return $this->value;
    }
}
