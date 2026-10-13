<?php

declare(strict_types=1);

namespace App\Approvals\Policies\Rules;

use App\AI\Tools\ToolKind;
use App\Approvals\ProposalKind;
use App\Support\Data\SensitivityClass;

/**
 * `automation.execute` — the §3 `execute(AuthorizedUiAction)` entry point, under
 * ADR-005 D1.6's per-action authorization.
 *
 * Three declarations carry the ADR:
 *
 *  - the schema names the frozen target columns D1.6 lists (app bundle id, window
 *    title, element role and label, action type) and `value_hash` rather than a
 *    value. A typed secret must not reach a policy input, so the field the model
 *    may supply is a hash, and `redacted_value` is the only text — D1.7's redaction
 *    applies before anything gets this far.
 *  - the destination is the app bundle id, against D1.5's v1 allowlist of exactly
 *    one entry, `com.apple.Safari`. This is the PHP half of D1.5's "two independent
 *    checks, both fail closed"; the Swift helper runs its own, and
 *    `browser_sessions.allowlist` is what the owner widens one app at a time.
 *  - `maxCallsPerRun: 25` is D1.8's "the model loop gets at most 25 actions per
 *    session before requiring a fresh grant", counted from `tool_runs` so a retried
 *    job cannot reset it.
 */
final readonly class UiActionRule implements ActionRule
{
    public const string TOOL = 'automation.execute';

    /** ADR-005 D1.5: v1 ships with exactly one allowlist entry. */
    public const string DEFAULT_BUNDLE_ID = 'com.apple.Safari';

    public function name(): string
    {
        return 'ui_action';
    }

    public function toolNames(): array
    {
        return [self::TOOL];
    }

    public function kind(): ProposalKind
    {
        return ProposalKind::UiAction;
    }

    /** The only kind that may carry a screenshot hash and a proposal reference. */
    public function toolKind(): ToolKind
    {
        return ToolKind::ComputerUse;
    }

    public function schema(): ArgumentSchema
    {
        return ArgumentSchema::of(
            required: [
                'session_id' => ArgumentType::Text,
                'app_bundle_id' => ArgumentType::Text,
                'window_title' => ArgumentType::Text,
                'element_role' => ArgumentType::Text,
                'element_label' => ArgumentType::Text,
                'action_type' => ArgumentType::Text,
            ],
            optional: [
                'redacted_value' => ArgumentType::Text,
                'value_hash' => ArgumentType::Text,
                'screenshot_sha256' => ArgumentType::Text,
            ],
        );
    }

    public function dataClasses(): array
    {
        return [SensitivityClass::Internal, SensitivityClass::Confidential];
    }

    public function destinations(): DestinationRule
    {
        return DestinationRule::appBundleIds(self::DEFAULT_BUNDLE_ID);
    }

    public function budget(): ActionBudget
    {
        return new ActionBudget(maxCallsPerRun: 25, maxCanonicalBytes: 8192, maxDestinations: 1);
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    /** A UI action is bound to a browser session, not to a mail account. */
    public function requiresAccountScope(): bool
    {
        return false;
    }
}
