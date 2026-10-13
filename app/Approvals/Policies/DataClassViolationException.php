<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * §4 input 4 — data class. The action declares a `SensitivityClass` the rule does
 * not permit, or declares none at all.
 *
 * "None at all" being a refusal is the deliberate half: there is no unclassified
 * default, so an action whose author never decided what data it touches is refused
 * rather than quietly treated as `internal`.
 */
final class DataClassViolationException extends PolicyRefusal
{
    protected const string EXPLANATION = 'the data class is missing or not one this rule permits';
}
