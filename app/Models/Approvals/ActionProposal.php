<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Approvals\FrozenProposalException;
use App\Approvals\ProposalKind;
use App\Approvals\ProposalStatus;
use App\Browser\ComputerUse\UiActionKind;
use App\Models\AI\AiRun;
use App\Models\Concerns\BusinessObject;
use App\Models\Organization;
use App\Models\User;
use App\Support\Json\Jcs;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One frozen, immutable proposal for an outbound action (§3.1 `action_proposals`;
 * §11; ADR-005 D1.6). The model only writes rows here; authority comes from a
 * matching `ActionApproval`, and only `App\Approvals` may turn the pair into an
 * `AuthorizedUiAction` or an `ApprovedOutgoingEmail`.
 *
 * Two mechanisms make "immutable after freeze" true rather than aspirational:
 *
 *  1. `proposal_hash` is `sha256:` over the RFC 8785 canonical form of `payload`
 *     (`hashPayload()`), copied onto the approval and recomputed by the executing
 *     provider — so a changed payload is *detected* at the moment of execution.
 *  2. The `saving` guard below refuses to persist a change to any column outside
 *     `MUTABLE_AFTER_FREEZE` once `frozen_at` is set — so it is not *written* in the
 *     first place. A unique index cannot express this one (it is a per-row rule, not
 *     a cross-row one), which is why it lives in the model and has its own test.
 *
 * @property string $id
 * @property string $organization_id
 * @property ProposalKind $kind
 * @property ProposalStatus $status
 * @property ?string $ai_run_id
 * @property ?string $trace_id
 * @property ?string $browser_session_id
 * @property string $proposed_by
 * @property ?string $requested_by_user_id
 * @property ?string $app_bundle_id
 * @property ?string $window_title
 * @property ?string $element_role
 * @property ?string $element_label
 * @property ?UiActionKind $action_type
 * @property ?string $redacted_value
 * @property ?string $value_hash
 * @property ?string $screenshot_sha256
 * @property array<string, mixed> $payload
 * @property string $proposal_hash
 * @property string $nonce
 * @property string $idempotency_key
 * @property CarbonImmutable $frozen_at
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $executed_at
 * @property ?CarbonImmutable $rejected_at
 * @property ?string $rejected_by_user_id
 * @property ?string $rejection_reason
 */
class ActionProposal extends Model
{
    use BusinessObject;

    /**
     * The only columns that may change after `frozen_at` is set: the status and the
     * timestamps that record what happened *to* the proposal. Nothing that an
     * approver read, and nothing a connector will act on, is in this list.
     *
     * @var list<string>
     */
    public const array MUTABLE_AFTER_FREEZE = [
        'status',
        'executed_at',
        'rejected_at',
        'rejected_by_user_id',
        'rejection_reason',
        'updated_at',
    ];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'kind' => ProposalKind::class,
            'status' => ProposalStatus::class,
            'action_type' => UiActionKind::class,
            'payload' => 'array',
            'frozen_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    /**
     * The §11 content hash: `sha256:` over the RFC 8785 canonical form of the frozen
     * payload. The same rule as the brief's `content_hash`, so one canonicaliser
     * serves both and a hash computed in PHP matches one computed from the fixtures'
     * JavaScript port.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hashPayload(array $payload): string
    {
        return 'sha256:'.hash('sha256', Jcs::canonicalize($payload));
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasOne<ActionApproval, $this> */
    public function approval(): HasOne
    {
        return $this->hasOne(ActionApproval::class, 'action_proposal_id');
    }

    /** @return BelongsTo<BrowserSession, $this> */
    public function browserSession(): BelongsTo
    {
        return $this->belongsTo(BrowserSession::class, 'browser_session_id');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Whether `proposal_hash` still matches `payload`. The executing provider calls
     * this before it acts, and refuses on `false` — an approval can never be
     * replayed against different content.
     */
    public function hashMatchesPayload(): bool
    {
        return hash_equals($this->proposal_hash, self::hashPayload($this->payload));
    }

    public function isExpired(DateTimeInterface $now): bool
    {
        return $now >= $this->expires_at;
    }

    /**
     * Fail closed (§11): a proposal is approvable only while it is pending, unexpired
     * and its content still hashes to what was frozen.
     */
    public function isApprovable(DateTimeInterface $now): bool
    {
        return $this->status->isApprovable()
            && ! $this->isExpired($now)
            && $this->hashMatchesPayload();
    }

    protected static function booted(): void
    {
        static::updating(function (self $proposal): void {
            if ($proposal->getOriginal('frozen_at') === null) {
                return;
            }

            $changed = array_values(array_diff(
                array_keys($proposal->getDirty()),
                self::MUTABLE_AFTER_FREEZE,
            ));

            if ($changed === []) {
                return;
            }

            throw new FrozenProposalException((string) $proposal->getKey(), $changed);
        });
    }
}
