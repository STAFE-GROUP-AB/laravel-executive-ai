<?php

declare(strict_types=1);

namespace App\Security\Retention;

use RuntimeException;

/**
 * Thrown when a `retention_policy` value resolves to nothing — neither a rule
 * stored in `data_retention_rules` nor a shipped default in `RetentionRules`.
 *
 * This is the whole point of landing the rules with the schema (S2-03, #86): a
 * dangling policy name is a row that silently never expires, so resolution fails
 * loudly instead of returning null and letting the purge job skip the row forever.
 */
final class UnknownRetentionRuleException extends RuntimeException
{
    /** @param  list<string>  $known */
    public static function named(string $name, array $known): self
    {
        sort($known);

        return new self(sprintf(
            'No retention rule named "%s". A `retention_policy` value must resolve to a stored rule or a shipped default; known defaults are: %s.',
            $name,
            implode(', ', $known),
        ));
    }

    public static function forTable(string $table): self
    {
        return new self(sprintf(
            'No retention rule governs the table "%s". Add it to RetentionRules::tableRules() (or plannedTableRules() until it exists) with the rule that expires its rows.',
            $table,
        ));
    }
}
