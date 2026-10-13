<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Security\Retention\PurgeAction;
use App\Security\Retention\RetentionBasis;
use App\Security\Retention\RetentionClock;
use App\Security\Retention\RetentionRule;
use App\Security\Retention\RetentionRules;
use App\Security\Retention\UnknownRetentionRuleException;
use App\Support\Data\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BackendProfile;

uses(DatabaseTransactions::class);

/*
 * S2-03 (#86): `data_retention_rules` must resolve a named rule for every
 * `retention_policy` value any table writes, and an **unknown** rule name must fail
 * here rather than produce a row that silently never expires. That is why the rules
 * land with the schema and not with the retention UI.
 *
 * Every test here is in the `backend-contract` group even though the file lives
 * under `tests/Feature`: resolution reads `data_retention_rules` back through
 * `RetentionRule::fromRow()`, and how an engine returns a JSON column, a boolean
 * and a nullable integer differs between the two ADR-002 D1 profiles. The
 * `server-profile` CI lane runs `--group=backend-contract`, so the group is what
 * decides whether these assertions are exercised on MySQL at all. The pure-logic
 * cases are tagged too: running them twice costs milliseconds and removes the
 * question of which ones were tagged.
 */

it('resolves every shipped rule by name', function (): void {
    expect(RetentionRules::names())->not->toBeEmpty();

    foreach (RetentionRules::names() as $name) {
        $rule = RetentionRules::resolve($name);

        expect($rule)->toBeInstanceOf(RetentionRule::class)
            ->and($rule->name)->toBe($name)
            ->and($rule->appliesTo)->not->toBeEmpty();
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('resolves a rule for every retention policy value a table may write', function (): void {
    foreach (RetentionRules::allTableRules() as $table => $name) {
        $rule = RetentionRules::resolve($name);

        expect($rule->governs($table))->toBeTrue("rule {$name} is mapped to {$table} but does not list it");
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('refuses an unknown rule name instead of returning a row that never expires', function (): void {
    expect(fn () => RetentionRules::resolve('keep_forever_i_guess'))
        ->toThrow(UnknownRetentionRuleException::class);

    expect(fn () => RetentionRules::default('crm_recordz'))
        ->toThrow(UnknownRetentionRuleException::class);

    expect(fn () => RetentionRules::forTable('a_table_nobody_governs'))
        ->toThrow(UnknownRetentionRuleException::class);

    // The message names the known rules, because the caller is a developer who just
    // wrote a typo into a `retention_policy` column.
    try {
        RetentionRules::resolve('crm_recordz');
    } catch (UnknownRetentionRuleException $exception) {
        expect($exception->getMessage())->toContain('crm_records');
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('never leaves a rule without a clock by omission', function (): void {
    foreach (RetentionRules::defaults() as $rule) {
        if ($rule->retainDays === null) {
            expect($rule->action)->toBe(PurgeAction::KeepUntilErased, "rule {$rule->name} has no retention period and must declare keep_until_erased");

            continue;
        }

        expect($rule->action)->not->toBe(PurgeAction::KeepUntilErased)
            ->and($rule->retainDays)->toBeGreaterThan(0)
            ->and($rule->neverExpires())->toBeFalse();
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('refuses to construct a rule that has no clock and does not say so', function (): void {
    expect(fn (): RetentionRule => new RetentionRule(
        name: 'quietly_forever',
        description: 'A rule with no period and a purge action.',
        basis: RetentionBasis::LegitimateInterests,
        clock: RetentionClock::CreatedAt,
        action: PurgeAction::Purge,
        retainDays: null,
        appliesTo: ['audit_events'],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn (): RetentionRule => new RetentionRule(
        name: 'clock_and_keep',
        description: 'A rule with a period that also claims to keep rows until erased.',
        basis: RetentionBasis::LegitimateInterests,
        clock: RetentionClock::CreatedAt,
        action: PurgeAction::KeepUntilErased,
        retainDays: 30,
        appliesTo: ['audit_events'],
    ))->toThrow(InvalidArgumentException::class);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('computes retain_until and the purgeable instant from the rule clock', function (): void {
    $written = CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC');

    $brief = RetentionRules::resolve('brief_history');

    expect($brief->retainUntil($written)->toDateTimeString())->toBe('2028-10-12 06:00:00')
        ->and($brief->purgeableAt($written)->toDateTimeString())->toBe('2028-11-11 06:00:00');

    $cursor = RetentionRules::resolve('sync_state');

    expect($cursor->retainUntil($written))->toBeNull()
        ->and($cursor->purgeableAt($written))->toBeNull()
        ->and($cursor->neverExpires())->toBeTrue();
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('keeps a floor under the rules a user may adjust', function (): void {
    foreach (RetentionRules::defaults() as $rule) {
        if (! $rule->userAdjustable) {
            expect($rule->minimumRetainDays)->toBeNull("rule {$rule->name} is fixed, so a minimum protects nothing");

            continue;
        }

        expect($rule->minimumRetainDays)->not->toBeNull("rule {$rule->name} is adjustable and needs a floor")
            ->and($rule->minimumRetainDays)->toBeLessThanOrEqual($rule->retainDays);
    }

    // The obligations keep a year even after an adjustment.
    foreach (['audit_log', 'delivery_receipts', 'approval_record', 'email_send_log'] as $name) {
        expect(RetentionRules::resolve($name)->minimumRetainDays)->toBe(365);
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('installs the shipped rules into data_retention_rules idempotently', function (): void {
    $organization = Organization::factory()->create();
    $organizationId = (string) $organization->id;

    $inserted = RetentionRules::install($organizationId);

    expect($inserted)->toBe(count(RetentionRules::names()))
        ->and(RetentionRules::install($organizationId))->toBe(0);

    $stored = DB::table(RetentionRules::TABLE)->where('organization_id', $organizationId)->pluck('name')->sort()->values()->all();
    $shipped = collect(RetentionRules::names())->sort()->values()->all();

    expect($stored)->toBe($shipped);

    foreach (DB::table(RetentionRules::TABLE)->where('organization_id', $organizationId)->get() as $row) {
        expect((bool) $row->is_default)->toBeTrue()
            ->and((bool) $row->is_active)->toBeTrue()
            ->and((string) $row->retention_policy)->toBe('governance_record')
            ->and(RetentionRule::fromRow((array) $row)->name)->toBe((string) $row->name);
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('prefers the stored rule over the shipped default for that workspace only', function (): void {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    RetentionRules::install((string) $organization->id);

    DB::table(RetentionRules::TABLE)
        ->where('organization_id', (string) $organization->id)
        ->where('name', 'health_telemetry')
        ->update(['retain_days' => 14, 'is_default' => false]);

    expect(RetentionRules::resolve('health_telemetry', (string) $organization->id)->retainDays)->toBe(14)
        ->and(RetentionRules::resolve('health_telemetry', (string) $other->id)->retainDays)->toBe(90)
        ->and(RetentionRules::resolve('health_telemetry')->retainDays)->toBe(90)
        ->and(RetentionRules::default('health_telemetry')->retainDays)->toBe(90);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('resolves a workspace rule the shipped defaults never named', function (): void {
    $organization = Organization::factory()->create();

    DB::table(RetentionRules::TABLE)->insert([
        'id' => (string) Str::ulid(),
        'organization_id' => (string) $organization->id,
        'name' => 'customer_imposed_ninety_days',
        'description' => 'A customer contract requires ninety days on their communications.',
        'legal_basis' => 'contract',
        'clock_column' => 'occurred_at',
        'purge_action' => 'purge',
        'retain_days' => 90,
        'grace_days' => 0,
        'minimum_retain_days' => null,
        'user_adjustable' => false,
        'applies_to' => json_encode(['communications'], JSON_THROW_ON_ERROR),
        'is_active' => true,
        'is_default' => false,
        'effective_from' => '2026-10-13 06:00:00',
        'sensitivity_class' => 'internal',
        'provenance' => json_encode(Provenance::job('RetentionRuleResolutionTest'), JSON_THROW_ON_ERROR),
    ]);

    $rule = RetentionRules::resolve('customer_imposed_ninety_days', (string) $organization->id);

    expect($rule->retainDays)->toBe(90)
        ->and($rule->governs('communications'))->toBeTrue()
        // …and it is still unknown to every other workspace, so a dangling name
        // elsewhere is not quietly satisfied by someone else's rule.
        ->and(fn () => RetentionRules::resolve('customer_imposed_ninety_days'))
        ->toThrow(UnknownRetentionRuleException::class);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('ignores a deactivated or soft-deleted stored rule and falls back to the default', function (): void {
    $organization = Organization::factory()->create();
    $organizationId = (string) $organization->id;

    RetentionRules::install($organizationId);

    DB::table(RetentionRules::TABLE)
        ->where('organization_id', $organizationId)
        ->where('name', 'backup_log')
        ->update(['retain_days' => 10, 'is_active' => false]);

    expect(RetentionRules::resolve('backup_log', $organizationId)->retainDays)->toBe(365);

    DB::table(RetentionRules::TABLE)
        ->where('organization_id', $organizationId)
        ->where('name', 'brief_history')
        ->update(['retain_days' => 10, 'deleted_at' => '2026-10-13 06:00:00']);

    expect(RetentionRules::resolve('brief_history', $organizationId)->retainDays)->toBe(730);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('governs the tables the schema actually has', function (): void {
    foreach (RetentionRules::tableRules() as $table => $name) {
        expect(Schema::hasTable($table))->toBeTrue("rule {$name} names a table that does not exist: {$table}")
            ->and(RetentionRules::forTable($table)->name)->toBe($name);
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

/*
 * `expires_at` is never a retention clock. Several tables carry one and in every
 * case it is a *business* deadline, not a purge deadline: the §11 approval window
 * on `action_proposals`/`action_approvals` (minutes — an approval that lapsed an
 * hour ago is exactly the row that proves who consented to what), ADR-005 D1.8's
 * 60-minute `browser_sessions` grant, ADR-004 D5's evidence lapse on
 * `ai_providers`, when a `memories` row stops being *active*, and when a push
 * subscription's endpoint stops being valid. `retain_until` — derived from the
 * rule's own clock — is the only column that means "the purge job may delete this
 * row". Raised by S2-02 (#85) while both branches were open; pinned here so a
 * later purge job cannot quietly adopt `expires_at` and destroy evidence that
 * still matters.
 */
it('never makes expires_at a retention clock', function (): void {
    $clocks = array_map(fn (RetentionClock $clock): string => $clock->value, RetentionClock::cases());

    expect($clocks)->not->toContain('expires_at')
        ->and($clocks)->not->toContain('evidence_expires_at');

    foreach (RetentionRules::defaults() as $rule) {
        expect($rule->clock->column())->not->toContain('expires_at', "rule {$rule->name}");
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

/*
 * The consent trail must outlive, or at least match, the audit trail it justifies.
 * `audit_events` records *that* an outbound action happened; `action_proposals` and
 * `action_approvals` record *that a human approved it*, with the approver identity,
 * the frozen input hash and the nonce (§11). If the approval rule expired first,
 * an incident could prove the send and not the consent — so the floor and the
 * period both have to keep up. Also raised by S2-02 (#85).
 */
it('keeps the consent trail at least as long as the audit trail', function (): void {
    $audit = RetentionRules::resolve('audit_log');
    $approval = RetentionRules::resolve('approval_record');

    expect($approval->retainDays)->toBeGreaterThanOrEqual($audit->retainDays)
        ->and($approval->minimumRetainDays)->toBeGreaterThanOrEqual($audit->minimumRetainDays);

    // The same reasoning for the two send logs: the receipt of what left the Mac.
    foreach (['delivery_receipts', 'email_send_log'] as $name) {
        expect(RetentionRules::resolve($name)->retainDays)->toBeGreaterThanOrEqual($audit->retainDays, $name);
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');
