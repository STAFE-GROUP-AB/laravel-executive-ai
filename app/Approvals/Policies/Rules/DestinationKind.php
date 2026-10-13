<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

/**
 * What kind of outside world a rule's action may reach (§4 input 5).
 *
 * `None` is the important one and the default: an action that reaches nothing
 * outside this machine must name no destination, so a read-only tool that suddenly
 * carries a recipient is a refusal and not a surprise.
 */
enum DestinationKind: string
{
    /** Nothing leaves the machine; the action must name no destination at all. */
    case None = 'none';

    /** Email recipients, which must be among the ones the frozen payload named. */
    case EmailRecipients = 'email_recipients';

    /** A macOS application bundle id, which must be in the rule's declared set. */
    case AppBundleIds = 'app_bundle_ids';
}

/*
 * Deliberately absent: an `HttpHosts` kind. An approved host set for outbound
 * traffic (ADR-005 D4.9) belongs to the egress guard, which answers "may a socket
 * open to this at all" — a different question from "did the approver see this
 * destination", and one the gate must not pretend to have answered. A kind nothing
 * declares would be untested code on the fail-closed path, so there is none.
 */
