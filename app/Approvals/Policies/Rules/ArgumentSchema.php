<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

/**
 * §4 input 2: the arguments a rule accepts, declared as data.
 *
 * Three properties, each a refusal rather than a tolerated oddity:
 *
 *  1. a declared-required argument that is absent is a refusal;
 *  2. an argument whose value is not of the declared type is a refusal;
 *  3. an argument that is **not declared at all** is a refusal. That third one is
 *     the one worth being deliberate about: the model writes the arguments, and a
 *     gate that ignores unknown keys lets it attach a field some later executor
 *     happens to read (`reply_to`, `bcc`, `force`) without any rule having
 *     permitted it. An allowlist of names is the only shape that closes that.
 *
 * `problems()` returns `name:type` pairs and never a value, because the result is
 * what reaches `tool_runs` and the audit trail.
 */
final readonly class ArgumentSchema
{
    /**
     * @param  array<string, ArgumentType>  $required
     * @param  array<string, ArgumentType>  $optional
     */
    public function __construct(
        public array $required,
        public array $optional = [],
    ) {}

    /**
     * @param  array<string, ArgumentType>  $required
     * @param  array<string, ArgumentType>  $optional
     */
    public static function of(array $required, array $optional = []): self
    {
        return new self($required, $optional);
    }

    /** A rule whose action takes no arguments at all. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string> `missing:<name>`, `type:<name>` and `unexpected:<name>`; never a value
     */
    public function problems(array $arguments): array
    {
        $problems = [];
        $declared = [...$this->required, ...$this->optional];

        foreach ($this->required as $name => $type) {
            if (! array_key_exists($name, $arguments)) {
                $problems[] = "missing:{$name}";
            }
        }

        foreach ($arguments as $name => $value) {
            $name = (string) $name;
            $type = $declared[$name] ?? null;

            if ($type === null) {
                $problems[] = "unexpected:{$name}";

                continue;
            }

            if (! $type->matches($value)) {
                $problems[] = "type:{$name}";
            }
        }

        sort($problems, SORT_STRING);

        return $problems;
    }

    /** @return list<string> */
    public function declaredNames(): array
    {
        $names = array_map(strval(...), [...array_keys($this->required), ...array_keys($this->optional)]);
        sort($names, SORT_STRING);

        return $names;
    }
}
