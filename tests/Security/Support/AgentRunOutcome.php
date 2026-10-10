<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use App\AI\Routing\PrivacyMode;
use App\Integrations\Mail\Dto\EmailAddress;

/**
 * What one agent run observably did. Every field is a recorded fact about the run's
 * effects, never a self-assessment: there is deliberately no `refused: bool`,
 * because "the run says it refused" is exactly the kind of claim a negative security
 * test may not accept (`tests/Security/README.md` contract rule 2 — "no exception
 * was thrown is never a pass").
 *
 * `Refusal` maps the corpus's effect vocabulary onto these fields plus the fakes'
 * recordings. An implementation of `AgentRunUnderTest` fills in what it knows; the
 * defaults describe a run that did nothing at all, which fails the `expected.must`
 * assertions rather than passing them silently (contract rule 3).
 */
final readonly class AgentRunOutcome
{
    /**
     * @param  string  $outputText  the chat answer shown to the owner
     * @param  list<string>  $toolCallsProposed  `AiToolCall::$name` for everything the model asked for
     * @param  list<string>  $toolCallsExecuted  names that actually ran after the PolicyGate
     * @param  list<string>  $userMessageKeys  translation keys shown to the owner; never translated strings
     * @param  list<array{text: string, source: ?string, suspicious: bool}>  $briefItems  §4.2 requires the source
     * @param  list<array{tool: string, approvalId: string, origin: string}>  $approvalsGranted  `origin` is `ui`, `model` or `content`; only `ui` is legitimate (ADR-005 D1.6)
     * @param  list<string>  $approvalsRequested  tools for which the owner was asked
     * @param  array<string, string>  $storedExcerpts  communication id => the text actually persisted
     * @param  list<string>  $toolCatalogue  tool names offered to the model, after the run
     * @param  EmailAddress|null  $sendingIdentity  the From of any proposal the run froze; null when it proposed none
     * @param  string|null  $accountScope  the connected account the run stayed inside
     * @param  string  $approvalModeAfter  `manual` unless something widened it
     */
    public function __construct(
        public string $outputText = '',
        public array $toolCallsProposed = [],
        public array $toolCallsExecuted = [],
        public array $userMessageKeys = [],
        public array $briefItems = [],
        public array $approvalsGranted = [],
        public array $approvalsRequested = [],
        public array $storedExcerpts = [],
        public array $toolCatalogue = [],
        public ?EmailAddress $sendingIdentity = null,
        public ?string $accountScope = null,
        public PrivacyMode $privacyModeAfter = PrivacyMode::LocalOnly,
        public string $approvalModeAfter = 'manual',
    ) {}

    /**
     * Everything the owner could read after the run: the chat answer and every brief
     * item. `allowed_output_mentions` and the secret/system-prompt assertions are
     * evaluated over exactly these strings.
     *
     * @return list<string>
     */
    public function outputSurfaces(): array
    {
        $surfaces = [$this->outputText];

        foreach ($this->briefItems as $item) {
            $surfaces[] = $item['text'];
        }

        return array_values(array_filter($surfaces, static fn (string $surface): bool => $surface !== ''));
    }

    /**
     * @return list<array{text: string, source: ?string, suspicious: bool}>
     */
    public function briefItemsCiting(string $sourceId): array
    {
        return array_values(array_filter(
            $this->briefItems,
            static fn (array $item): bool => $item['source'] === $sourceId,
        ));
    }
}
