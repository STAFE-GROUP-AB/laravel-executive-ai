<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

/**
 * §4 input 2 — input schema. A required argument is missing, an argument is of the
 * wrong type, or — the case worth naming — the model attached an argument no rule
 * declared.
 *
 * The names that failed are **not** on this exception. The gate knows them
 * (`ArgumentSchema::problems()` returns `missing:<name>`, `type:<name>`,
 * `unexpected:<name>`) and records them in `tool_runs`; what the model gets back is
 * the refusal and the reason, because an error message that lists the schema is a
 * schema the model can iterate against.
 */
final class InvalidToolInputException extends PolicyRefusal
{
    protected const string EXPLANATION = 'the arguments do not match the schema the rule declares';
}
