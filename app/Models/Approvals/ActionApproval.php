<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Models\AuthDevice;
use App\Models\Concerns\BusinessObject;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One human approval of one frozen proposal (§3.1 `action_approvals`; ADR-005 D1.6
 * approver identity and time; §11 approver identity, expiry, nonce, idempotency,
 * fail closed). Append-only: no soft delete, and the only update a row ever takes is
 * the `consumed_at` stamp when the nonce is spent.
 *
 * `action_approvals_proposal_unique` and `action_approvals_nonce_unique` are the
 * guarantee: a replayed approval cannot be *recorded*, which is a stronger statement
 * than it being detected afterwards (the argument ADR-009 D1.2 makes for the brief's
 * single-send index). `user_agent` and `ip_address` are kept on the row as re-auth
 * evidence and are hidden from serialisation.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $action_proposal_id
 * @property string $nonce
 * @property string $proposal_hash
 * @property string $approver_user_id
 * @property CarbonImmutable $approved_at
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $reauth_at
 * @property ?string $auth_device_id
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property ?CarbonImmutable $consumed_at
 */
class ActionApproval extends Model
{
    use BusinessObject;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['nonce', 'ip_address', 'user_agent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'approved_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'reauth_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ActionProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ActionProposal::class, 'action_proposal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /** @return BelongsTo<AuthDevice, $this> */
    public function authDevice(): BelongsTo
    {
        return $this->belongsTo(AuthDevice::class, 'auth_device_id');
    }

    public function isExpired(DateTimeInterface $now): bool
    {
        return $now >= $this->expires_at;
    }

    /**
     * Fail closed (§11): the approval may authorise execution only while it is
     * unexpired, its nonce is unspent, and the hash the approver saw still matches
     * the proposal's frozen payload.
     */
    public function authorises(ActionProposal $proposal, DateTimeInterface $now): bool
    {
        if ($this->action_proposal_id !== $proposal->getKey()) {
            return false;
        }

        if ($this->consumed_at !== null || $this->isExpired($now)) {
            return false;
        }

        if (! hash_equals($this->proposal_hash, $proposal->proposal_hash)) {
            return false;
        }

        return $proposal->hashMatchesPayload();
    }
}
