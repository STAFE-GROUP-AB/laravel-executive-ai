<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\Security\Retention\RetentionRules;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts the §3.1 retention columns on a row this application writes.
 *
 * S2-03's argument is that `retention_policy` holding a value nothing resolves
 * is worse than holding nothing: the purge job skips such a row forever, which
 * is data kept by accident (`RetentionRules`' own docblock). So the policy name
 * is never a literal in a form or a migration default — it is read from
 * `RetentionRules::forTable()`, which throws on a table no rule governs, and a
 * CRM table that lost its rule would fail here rather than quietly accumulate
 * unexpirable rows.
 *
 * `retain_until` is then derived from the rule's **own clock**, not from "now":
 *
 * - `crm_records` (customers, projects, tasks and their links) is
 *   `keep_until_erased` with the `deleted_at` clock and no period, so
 *   `retainUntil()` is null and stays null — a customer record lives as long as
 *   the relationship.
 * - `contact_personal_data` (contacts and their addresses) runs three years off
 *   `updated_at`, so every edit moves the date forward, which is what "three
 *   years without activity" means.
 *
 * Writing it on every save rather than once on create is deliberate for that
 * second case: a clock column that moves must move the derived date with it.
 */
final class RetentionStamp
{
    /**
     * The rule's columns for `$model`, ready to merge into an insert or update.
     *
     * @return array{retention_policy: string, retain_until: ?CarbonImmutable}
     */
    public static function columnsFor(Model $model, CarbonImmutable $now): array
    {
        $rule = RetentionRules::forTable($model->getTable(), self::organizationIdOf($model));
        $clockValue = self::clockValue($model, $rule->clock->column(), $now);

        return [
            'retention_policy' => $rule->name,
            'retain_until' => $clockValue === null ? null : $rule->retainUntil($clockValue),
        ];
    }

    /** Stamps `$model` in place without saving it. */
    public static function apply(Model $model, CarbonImmutable $now): void
    {
        $model->forceFill(self::columnsFor($model, $now));
    }

    /**
     * The instant the rule counts from. A clock column the row has not filled
     * yet — `deleted_at` on a live row — means the countdown has not started,
     * and null is the honest answer rather than today's date.
     */
    private static function clockValue(Model $model, string $column, CarbonImmutable $now): ?CarbonImmutable
    {
        $value = $model->getAttribute($column);

        if ($value === null) {
            // `created_at` and `updated_at` are filled by the save this stamp
            // is part of, so the instant of that save is the clock value.
            return in_array($column, ['created_at', 'updated_at'], true) ? $now : null;
        }

        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }

    private static function organizationIdOf(Model $model): ?string
    {
        $organizationId = $model->getAttribute('organization_id');

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
