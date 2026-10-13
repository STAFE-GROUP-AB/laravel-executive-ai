<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * §4 input 5 — destination. The action would reach somewhere its rule does not
 * permit: a destination of the wrong shape, one outside a declared set, or — the
 * one that matters most — one that is **not in the frozen payload**.
 *
 * That last case is `recipient_added` from the §16.7 effect vocabulary: an address
 * that arrived in a mail body, or in a later model turn, is not among the recipients
 * the approver read, so it cannot become a destination.
 */
final class DestinationNotAllowedException extends PolicyRefusal
{
    protected const string EXPLANATION = 'the destination is not one the frozen, approved payload named';
}
