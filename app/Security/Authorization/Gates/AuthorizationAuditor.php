<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

use App\Models\Organization;
use App\Models\User;
use App\Security\Audit\AuditLog;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Registered as the Gate's `after` hook, so **every** authorization decision in
 * the application passes through here exactly once — a policy method, a named
 * gate, the IPC's `OwnerAbilities`, a job's check, a tool's check.
 *
 * Threat model **T-46**: the audit row is the only forensic artefact a refusal
 * leaves behind, so a refusal that is silently dropped is a refusal nobody can
 * review afterwards. What gets written:
 *
 * - **every refusal**, as `outcome: refused`. A refusal is never not audited —
 *   including the one where *nothing answered* (no gate, no policy, or a guest
 *   at a gate that takes a `User`), which `Gate::authorize()` turns into a 403
 *   and which would otherwise be the one 403 with no row behind it.
 * - **every allow of a mutating ability**, as `outcome: ok` — the before-image
 *   of the change, naming who was allowed to do what to which row.
 * - **nothing** for an allowed read (`Abilities::READ_ONLY`), which would be a
 *   row per page render and would bury the two cases above.
 *
 * The row names the actor (`user:<ulid>` in the §3.1 provenance grammar, or
 * `guest`), the ability, the subject type and id, the outcome and the T-73
 * surface. `context` holds references only — an ability name, a class name, a
 * ULID, the surface — never a payload, never a brief, never an image path; the
 * `Redactor` inside `AuditLog` is the second layer, not the design (ADR-003 D7
 * row 8), and `AuditNoBlobTest` keeps the table itself free of blobs.
 *
 * An `after` hook must not change the decision it observes: `record()` returns
 * void and the closure that wraps it returns null, so `callAfterCallbacks()`
 * leaves a `false` result false and a null result null.
 */
final class AuthorizationAuditor
{
    public const string ACTION_PREFIX = 'authz.';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly SurfaceContext $surfaces,
    ) {}

    /**
     * @param  array<int, mixed>  $arguments  the gate arguments: the subject, then any extra context
     */
    public function record(?Authenticatable $user, string $ability, bool|Response|null $result, array $arguments): void
    {
        $allowed = $result instanceof Response ? $result->allowed() : $result === true;

        if ($allowed && ! Abilities::isMutating($ability)) {
            return;
        }

        $subject = $arguments[0] ?? null;

        $this->audit->record(
            action: self::ACTION_PREFIX.$ability,
            actor: $this->actor($user),
            outcome: $allowed ? AuditLog::OUTCOME_OK : AuditLog::OUTCOME_REFUSED,
            subjectType: $this->subjectType($subject),
            subjectId: $this->subjectId($subject),
            context: [
                'ability' => $ability,
                'surface' => $this->surfaces->current()->value,
                'result' => $allowed ? 'allowed' : 'refused',
                ...($this->reason($result) === null ? [] : ['reason' => $this->reason($result)]),
            ],
            organizationId: $this->organizationId($subject),
        );
    }

    /**
     * A refusal that never reaches the Gate, so the `after` hook cannot see
     * it: a queued job whose actor has been deleted since dispatch. T-46 is
     * explicit that a refusal must leave a row, and "the check could not even
     * be run" is a refusal.
     */
    public function refuse(string $actor, string $ability, string $reason, mixed $subject = null): void
    {
        $this->audit->record(
            action: self::ACTION_PREFIX.$ability,
            actor: $actor,
            outcome: AuditLog::OUTCOME_REFUSED,
            subjectType: $this->subjectType($subject),
            subjectId: $this->subjectId($subject),
            context: [
                'ability' => $ability,
                'surface' => $this->surfaces->current()->value,
                'result' => 'refused',
                'reason' => $reason,
            ],
            organizationId: $this->organizationId($subject),
        );
    }

    private function actor(?Authenticatable $user): string
    {
        if ($user instanceof User) {
            return "user:{$user->id}";
        }

        return $user === null ? 'guest' : 'user:'.(string) $user->getAuthIdentifier();
    }

    /**
     * The class the decision was about. An anonymous class names itself with
     * the file and line it was declared on after a NUL byte, which is neither
     * a class anybody can look up nor something that belongs in an audit
     * column, so only the part before the NUL is kept
     * (`Illuminate\Database\Eloquent\Model@anonymous`). `AuditLog` clamps what
     * is still too long for the column.
     */
    private function subjectType(mixed $subject): ?string
    {
        $class = match (true) {
            $subject instanceof Model => $subject::class,
            is_string($subject) && class_exists($subject) => $subject,
            default => null,
        };

        if ($class === null || ! str_contains($class, "\0")) {
            return $class;
        }

        return (string) strstr($class, "\0", true);
    }

    private function subjectId(mixed $subject): ?string
    {
        if (! $subject instanceof Model) {
            return null;
        }

        $key = $subject->getKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * The organization the decision was about, so an incident review can read
     * the refusals that concerned one tenant — including the ones where
     * somebody else was probing it. Taken from the subject row; a decision
     * with no row (a page ability) has none, and
     * `audit_events.organization_id` is nullable for exactly that case.
     *
     * Only a **persisted** subject sets it, and that is a correctness
     * requirement rather than tidiness: the column is a foreign key to
     * `organizations`, so an unsaved object carrying an `organization_id` that
     * does not exist would make the insert fail — turning a 403 with a record
     * into a 500 with none, which is the worst of the available outcomes. A
     * row that came out of the database has its organization guaranteed by its
     * own foreign key; the rest of the row's identity is still recorded in
     * `subject_type` and `subject_id`.
     */
    private function organizationId(mixed $subject): ?string
    {
        if (! $subject instanceof Model || ! $subject->exists) {
            return null;
        }

        if ($subject instanceof Organization) {
            $key = $subject->getKey();

            return $key === null ? null : (string) $key;
        }

        $organizationId = $subject->getAttribute('organization_id');

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }

    /**
     * Why the decision went the way it did, when there is anything to say.
     *
     * `null` is the case worth spelling out: it means **nothing answered** —
     * no gate is defined for the ability, no policy matched, or the ability
     * callback could not be called with the user at hand (a guest reaching a
     * gate that takes a `User`). `Gate::authorize()` turns that into a
     * refusal, so the audit trail must too: a 403 the owner cannot find a row
     * for is exactly the silent drop T-46 forbids. It is recorded as refused
     * with `reason: unanswered`, which also makes a missing gate visible as a
     * defect rather than as a quiet deny-all.
     *
     * A `Response`'s message is written by a policy of ours, never by an
     * external party, so it is a reference and not content. It still passes
     * through the Redactor with the rest of the context.
     */
    private function reason(bool|Response|null $result): ?string
    {
        if ($result === null) {
            return 'unanswered';
        }

        return $result instanceof Response ? $result->message() : null;
    }
}
