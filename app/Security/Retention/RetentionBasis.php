<?php

declare(strict_types=1);

namespace App\Security\Retention;

/**
 * The lawful basis a retention rule rests on (§10.3: "consent/legal basis
 * notering"). Only the four GDPR Article 6 bases this product can honestly claim
 * are offered — `vital_interests` and `public_task` are not ours to assert, so they
 * are absent rather than available and unused.
 *
 * The basis is what makes a rule's number defensible: a seven-year clock on
 * `audit_events` is a legal obligation, a three-year clock on contact data is a
 * legitimate interest that has to be balanced, and an opt-in push device is held on
 * consent that can be withdrawn.
 */
enum RetentionBasis: string
{
    case Consent = 'consent';

    case Contract = 'contract';

    case LegalObligation = 'legal_obligation';

    case LegitimateInterests = 'legitimate_interests';

    /**
     * Whether withdrawing the basis removes the row immediately rather than when a
     * clock runs out — true for consent, which the data subject controls.
     */
    public function isWithdrawable(): bool
    {
        return $this === self::Consent;
    }
}
