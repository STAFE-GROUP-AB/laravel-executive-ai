<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Tools\PolicyDecision;
use App\AI\Tools\ToolKind;
use App\AI\Tools\ToolRunOutcome;
use App\Models\Approvals\ActionProposal;
use App\Models\Concerns\BusinessObject;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tool invocation inside an AI run (§3.1 `tool_runs`), and the row the §1.2
 * `/assistant` tool-activity surface reads. Append-only: no soft delete.
 *
 * Two constraints are structural rather than conventional. `policy_decision` is not
 * nullable, so a tool that was never put to the server-side `PolicyGate` has no
 * representable row (§4). And there is **no binary column and no image path**: a
 * computer-use row carries `screenshot_sha256` plus dimensions, app bundle id and
 * window title and nothing else about the frame (ADR-003 D7 row 8, ADR-005 D1.7) —
 * `tests/Security/AuditNoBlobTest.php` keeps it that way.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $ai_run_id
 * @property string $trace_id
 * @property string $tool_name
 * @property ToolKind $tool_kind
 * @property ?string $mcp_server_id
 * @property int $step
 * @property ?array<string, mixed> $arguments_redacted
 * @property ?array<string, mixed> $result_redacted
 * @property PolicyDecision $policy_decision
 * @property ?string $policy_reason
 * @property ?string $action_proposal_id
 * @property ToolRunOutcome $outcome
 * @property ?AiErrorKind $error_kind
 * @property ?string $error_message
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?int $duration_ms
 * @property ?string $app_bundle_id
 * @property ?string $window_title
 * @property ?string $screenshot_sha256
 * @property ?int $screenshot_width
 * @property ?int $screenshot_height
 */
class ToolRun extends Model
{
    use BusinessObject;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'tool_kind' => ToolKind::class,
            'step' => 'integer',
            'arguments_redacted' => 'array',
            'result_redacted' => 'array',
            'policy_decision' => PolicyDecision::class,
            'outcome' => ToolRunOutcome::class,
            'error_kind' => AiErrorKind::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'screenshot_width' => 'integer',
            'screenshot_height' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /** @return BelongsTo<ActionProposal, $this> */
    public function actionProposal(): BelongsTo
    {
        return $this->belongsTo(ActionProposal::class, 'action_proposal_id');
    }

    public function wasDenied(): bool
    {
        return $this->policy_decision === PolicyDecision::Denied
            || $this->outcome === ToolRunOutcome::Denied;
    }
}
