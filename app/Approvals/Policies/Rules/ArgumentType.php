<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

/**
 * The argument types a rule may declare (§4 input 2, "input schema"). Deliberately
 * a short closed list rather than JSON Schema: a rule declares the shape it accepts
 * so the gate can reject everything else, and a vocabulary the gate can enumerate
 * is what makes `PolicyGateFailsClosedTest` able to assert that an unexpected
 * argument is a refusal rather than an ignored extra.
 */
enum ArgumentType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case TextList = 'text_list';
    case Map = 'map';

    public function matches(mixed $value): bool
    {
        return match ($this) {
            self::Text => is_string($value),
            self::Integer => is_int($value),
            self::Boolean => is_bool($value),
            self::TextList => $this->isTextList($value),
            self::Map => is_array($value) && ! array_is_list($value),
        };
    }

    private function isTextList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                return false;
            }
        }

        return true;
    }
}
