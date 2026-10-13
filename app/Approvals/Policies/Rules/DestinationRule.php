<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

use App\Approvals\Policies\PolicyReason;
use App\Approvals\Policies\ProposedAction;

/**
 * §4 input 5: where a rule's action may reach, declared as data.
 *
 * The strongest check here is not an allowlist of addresses — the owner's
 * correspondents are not knowable when a rule is written — it is
 * **`mustBeInFrozenPayload`**: every destination must already appear in the payload
 * that was frozen and that the approver read. A recipient that arrived after the
 * freeze, from a mail body or from a second model turn, is therefore not in the
 * payload and is refused. That is `recipient_added` and `from_address_changed` from
 * the §16.7 effect vocabulary, as a check rather than as a hope.
 *
 * What this rule is **not**: an egress guard. It answers "is this destination one
 * the frozen, approved content named, of the kind this rule permits". Whether a
 * socket may be opened to a host at all — DNS rebinding, link-local ranges, the
 * `LOCAL_ONLY` tier — is `App\Security\Egress` (S2-14) and runs as well as this,
 * not instead of it.
 */
final readonly class DestinationRule
{
    /**
     * Enough of an address to tell a destination from a sentence. Deliberately not a
     * full RFC 5322 parser: canonicalisation is
     * `App\Integrations\Mail\Dto\EmailAddress`'s job, and the containment check
     * below is what actually constrains the value.
     */
    private const string ADDRESS = '/^[^@\s,;<>"]+@[^@\s,;<>"]+\.[A-Za-z]{2,}$/';

    /**
     * @param  list<string>  $allowed  hosts or bundle ids, for the kinds that declare a set
     * @param  list<string>  $payloadFields  the payload keys a destination may come from
     */
    public function __construct(
        public DestinationKind $kind,
        public array $allowed = [],
        public array $payloadFields = [],
        public bool $mustBeInFrozenPayload = true,
    ) {}

    public static function none(): self
    {
        return new self(DestinationKind::None);
    }

    /**
     * @param  list<string>  $payloadFields
     */
    public static function emailRecipients(array $payloadFields = ['to', 'cc', 'bcc']): self
    {
        return new self(DestinationKind::EmailRecipients, payloadFields: $payloadFields);
    }

    public static function appBundleIds(string ...$bundleIds): self
    {
        return new self(DestinationKind::AppBundleIds, allowed: array_values($bundleIds), payloadFields: ['app_bundle_id']);
    }

    /**
     * The reason to refuse, or null when every destination is permitted.
     */
    public function refusal(ProposedAction $action): ?PolicyReason
    {
        if ($this->kind === DestinationKind::None) {
            return $action->destinations === [] ? null : PolicyReason::DestinationNotAllowed;
        }

        if ($action->destinations === []) {
            // A rule that declares a destination kind describes an action that
            // reaches outside; one with no destination at all has nothing for this
            // check to bound, which is the shape a default-allow would take.
            return PolicyReason::DestinationNotAllowed;
        }

        $fromPayload = $this->destinationsInPayload($action);

        foreach ($action->destinations as $destination) {
            if (! $this->isWellFormed($destination)) {
                return PolicyReason::DestinationNotAllowed;
            }

            if ($this->allowed !== [] && ! in_array($this->normalize($destination), $this->allowed, true)) {
                return PolicyReason::DestinationNotAllowed;
            }

            if ($this->mustBeInFrozenPayload && ! in_array($this->normalize($destination), $fromPayload, true)) {
                return PolicyReason::DestinationNotAllowed;
            }
        }

        return null;
    }

    private function isWellFormed(string $destination): bool
    {
        if (trim($destination) === '') {
            return false;
        }

        return match ($this->kind) {
            DestinationKind::EmailRecipients => preg_match(self::ADDRESS, $destination) === 1,
            DestinationKind::AppBundleIds => preg_match('/^[A-Za-z0-9.-]+$/', $destination) === 1,
            DestinationKind::None => false,
        };
    }

    /**
     * Every destination the frozen payload names, flattened out of the declared
     * fields. A field that is not declared is not read, so a payload key the rule
     * never mentioned cannot become a destination.
     *
     * @return list<string>
     */
    private function destinationsInPayload(ProposedAction $action): array
    {
        $found = [];

        foreach ($this->payloadFields as $field) {
            $value = $action->payload[$field] ?? null;

            if (is_string($value)) {
                $found[] = $this->normalize($value);

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $entry) {
                if (is_string($entry)) {
                    $found[] = $this->normalize($entry);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Comparison form, per kind. An email domain is case-insensitive, so folding
     * case there is what makes `Zoe@Kund.Example` and `zoe@kund.example` the same
     * destination rather than one the containment check would miss. A bundle id is
     * **not** folded: `com.apple.Safari` is the identifier Apple publishes, the
     * allowlist is written that way, and folding one side of an exact-match check
     * would have refused the one app ADR-005 D1.5 permits.
     */
    private function normalize(string $value): string
    {
        $value = trim($value);

        return $this->kind === DestinationKind::EmailRecipients ? mb_strtolower($value) : $value;
    }
}
