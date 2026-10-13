<?php

declare(strict_types=1);

namespace App\Security\Retention;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One named retention rule: the thing every `retention_policy` column on every
 * business object points at (data-model §1.1). A rule is the full answer to "when
 * does this row go away, measured from what, on what legal basis, and may the owner
 * change it" — not a number of days on its own.
 *
 * The constructor enforces the invariants that keep the set honest:
 *
 *  - `retainDays === null` **if and only if** the action is `KeepUntilErased`. A
 *    clockless rule is therefore always a declared decision; there is no way to
 *    write a rule that forgets to say when it expires.
 *  - `minimumRetainDays` only exists on rules the owner may change, and never
 *    exceeds the default — it is the floor the retention UI (S2-14 onwards) may not
 *    let a user go below, which is how a seven-year audit obligation survives
 *    contact with a settings pane.
 *  - a rule applies to at least one table, and names them explicitly rather than by
 *    pattern, so the governance contract test can check both directions: every
 *    table is governed, and every governed table exists (or is declared as landing
 *    with a later item).
 */
final readonly class RetentionRule
{
    /** @param  list<string>  $appliesTo */
    public function __construct(
        public string $name,
        public string $description,
        public RetentionBasis $basis,
        public RetentionClock $clock,
        public PurgeAction $action,
        public ?int $retainDays,
        public int $graceDays = 0,
        public ?int $minimumRetainDays = null,
        public bool $userAdjustable = false,
        public array $appliesTo = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $this->name) !== 1) {
            throw new InvalidArgumentException("A retention rule name is a lower-case snake_case token of at most 64 characters; got \"{$this->name}\".");
        }

        if (trim($this->description) === '') {
            throw new InvalidArgumentException("Retention rule \"{$this->name}\" needs a description: the rule is shown to the owner and quoted in a GDPR answer.");
        }

        if (($this->retainDays === null) !== ($this->action === PurgeAction::KeepUntilErased)) {
            throw new InvalidArgumentException("Retention rule \"{$this->name}\" must either carry a retention period or declare the action `keep_until_erased`; a rule without a clock is never implicit.");
        }

        if ($this->retainDays !== null && $this->retainDays < 1) {
            throw new InvalidArgumentException("Retention rule \"{$this->name}\" must retain for at least one day.");
        }

        if ($this->graceDays < 0) {
            throw new InvalidArgumentException("Retention rule \"{$this->name}\" cannot have a negative grace period.");
        }

        if ($this->minimumRetainDays !== null && ! $this->userAdjustable) {
            throw new InvalidArgumentException("Retention rule \"{$this->name}\" is not user-adjustable, so a minimum period has nothing to protect.");
        }

        if ($this->minimumRetainDays !== null && ($this->minimumRetainDays < 1 || ($this->retainDays !== null && $this->minimumRetainDays > $this->retainDays))) {
            throw new InvalidArgumentException("Retention rule \"{$this->name}\" has a minimum period outside its own retention period.");
        }

        if ($this->appliesTo === []) {
            throw new InvalidArgumentException("Retention rule \"{$this->name}\" applies to no table; an unused rule is a rule nobody maintains.");
        }

        foreach ($this->appliesTo as $table) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
                throw new InvalidArgumentException("Retention rule \"{$this->name}\" names a table that is not a snake_case identifier: \"{$table}\".");
            }
        }
    }

    public function neverExpires(): bool
    {
        return $this->retainDays === null;
    }

    /**
     * The `retain_until` value a row written now gets: the earliest instant the
     * purge job may consider it, measured from the rule's own clock. Null for a
     * clockless rule, which is also what `retain_until` stays on those rows.
     */
    public function retainUntil(DateTimeInterface $clockValue): ?CarbonImmutable
    {
        if ($this->retainDays === null) {
            return null;
        }

        return CarbonImmutable::instance($clockValue)->utc()->addDays($this->retainDays);
    }

    /**
     * When the row may actually be removed: `retain_until` plus the grace period
     * that gives an accidental deletion an undo window.
     */
    public function purgeableAt(DateTimeInterface $clockValue): ?CarbonImmutable
    {
        return $this->retainUntil($clockValue)?->addDays($this->graceDays);
    }

    public function governs(string $table): bool
    {
        return in_array($table, $this->appliesTo, true);
    }

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     legal_basis: string,
     *     clock_column: string,
     *     purge_action: string,
     *     retain_days: ?int,
     *     grace_days: int,
     *     minimum_retain_days: ?int,
     *     user_adjustable: bool,
     *     applies_to: list<string>
     * }
     */
    public function toRow(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'legal_basis' => $this->basis->value,
            'clock_column' => $this->clock->value,
            'purge_action' => $this->action->value,
            'retain_days' => $this->retainDays,
            'grace_days' => $this->graceDays,
            'minimum_retain_days' => $this->minimumRetainDays,
            'user_adjustable' => $this->userAdjustable,
            'applies_to' => $this->appliesTo,
        ];
    }

    /**
     * Rebuild a rule from its `data_retention_rules` row, so a rule the owner
     * adjusted resolves to the adjusted numbers and not to the shipped default.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        $appliesTo = $row['applies_to'] ?? [];

        if (is_string($appliesTo)) {
            $appliesTo = json_decode($appliesTo, true, 4, JSON_THROW_ON_ERROR);
        }

        if (! is_array($appliesTo)) {
            throw new InvalidArgumentException("The stored retention rule \"{$row['name']}\" must list the tables it applies to as an array.");
        }

        $tables = [];

        foreach ($appliesTo as $table) {
            if (! is_string($table)) {
                throw new InvalidArgumentException("The stored retention rule \"{$row['name']}\" lists a table that is not a string.");
            }

            $tables[] = $table;
        }

        return new self(
            name: (string) $row['name'],
            description: (string) $row['description'],
            basis: RetentionBasis::from((string) $row['legal_basis']),
            clock: RetentionClock::from((string) $row['clock_column']),
            action: PurgeAction::from((string) $row['purge_action']),
            retainDays: isset($row['retain_days']) ? (int) $row['retain_days'] : null,
            graceDays: (int) ($row['grace_days'] ?? 0),
            minimumRetainDays: isset($row['minimum_retain_days']) ? (int) $row['minimum_retain_days'] : null,
            userAdjustable: (bool) ($row['user_adjustable'] ?? false),
            appliesTo: $tables,
        );
    }
}
