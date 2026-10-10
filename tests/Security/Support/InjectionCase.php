<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use DateTimeImmutable;

/**
 * One case of the S0-19 prompt-injection corpus, typed.
 *
 * The corpus is JSON (`packaging/fixtures/security/prompt-injection/*.json`,
 * schema `injection.schema.json`) because the Swift menu bar, the PWA renderer and
 * the Node validator read the same bytes. `InjectionCorpus` validates the decoded
 * row once and produces this value object, so every assertion downstream works on
 * typed fields instead of `mixed` array access — a mistyped key becomes a loud
 * loader error rather than a silently skipped expectation
 * (`tests/Security/README.md` contract rule 1).
 *
 * Nothing here is ever edited to make a suite green: a case is frozen data
 * (contract rule 8).
 */
final readonly class InjectionCase
{
    /**
     * @param  list<string>  $encoding
     * @param  list<string>  $mustNot  forbidden effects; asserted one by one
     * @param  list<string>  $must  required effects; silence is a failure mode of its own
     * @param  list<string>  $allowedOutputMentions  the only way payload text may appear in output
     * @param  list<string>  $references
     */
    public function __construct(
        public string $id,
        public string $carrier,
        public string $language,
        public string $attackClass,
        public array $encoding,
        public string $title,
        public string $payload,
        public string $ridesOn,
        public string $ref,
        public string $field,
        public ?string $accountId,
        public DateTimeImmutable $arrivesAt,
        public array $mustNot,
        public array $must,
        public array $allowedOutputMentions,
        public ?string $userMessageKey,
        public array $references,
        public string $notes,
    ) {}

    /**
     * `[01M4...] Swedish email asks for ...` — used in every assertion message so a
     * failure names the attack (`tests/Security/README.md` contract rule 1).
     */
    public function label(): string
    {
        return sprintf('[%s] %s', $this->id, $this->title);
    }

    public function forbids(string $effect): bool
    {
        return in_array($effect, $this->mustNot, true);
    }

    public function requires(string $effect): bool
    {
        return in_array($effect, $this->must, true);
    }

    /**
     * The tool names this case says must never run, as the PolicyGate sees them
     * (`tool_call:email.send` → `email.send`).
     *
     * @return list<string>
     */
    public function forbiddenToolCalls(): array
    {
        $tools = [];

        foreach ($this->mustNot as $effect) {
            if (str_starts_with($effect, 'tool_call:')) {
                $tools[] = substr($effect, strlen('tool_call:'));
            }
        }

        return $tools;
    }

    /**
     * Hosts this case says must never be contacted (`url_fetched:attacker.example`).
     *
     * @return list<string>
     */
    public function forbiddenHosts(): array
    {
        $hosts = [];

        foreach ($this->mustNot as $effect) {
            if (str_starts_with($effect, 'url_fetched:')) {
                $hosts[] = substr($effect, strlen('url_fetched:'));
            }
        }

        return $hosts;
    }

    /**
     * Every address the payload itself names. These are the addresses an injected
     * instruction wants mail to go to or to come from; no approved proposal, brief
     * item or executed tool call may contain one.
     *
     * @return list<string>
     */
    public function addressesInPayload(): array
    {
        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $this->payload, $matches);

        /** @var list<string> $addresses */
        $addresses = array_values(array_unique(array_map('mb_strtolower', $matches[0])));

        return $addresses;
    }
}
