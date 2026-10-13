<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Browser\ComputerUse\AllowlistEntry;
use App\Browser\ComputerUse\AutomationSession;
use App\Browser\ComputerUse\AutomationSessionState;
use App\Models\AuthDevice;
use App\Models\Concerns\BusinessObject;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One automation session and its session grant (§3.1 `browser_sessions`, ADR-005
 * D1). The grant covers `observe()` within `allowlist`; every `execute()` needs its
 * own `ActionProposal` and `ActionApproval`.
 *
 * No screenshot and no path to one is ever stored here (ADR-005 D1.7).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $grant_id
 * @property AutomationSessionState $state
 * @property list<array{bundle_id: string, window_title_pattern?: ?string}> $allowlist
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $expires_at
 * @property int $idle_timeout_seconds
 * @property ?CarbonImmutable $last_activity_at
 * @property int $max_actions
 * @property int $actions_taken
 * @property ?CarbonImmutable $reauth_at
 * @property ?string $auth_device_id
 * @property ?CarbonImmutable $ended_at
 * @property ?string $end_reason
 * @property ?CarbonImmutable $panic_stopped_at
 * @property ?string $helper_version
 * @property ?string $peekaboo_version
 */
class BrowserSession extends Model
{
    use BusinessObject;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'state' => AutomationSessionState::class,
            'allowlist' => 'array',
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'idle_timeout_seconds' => 'integer',
            'last_activity_at' => 'immutable_datetime',
            'max_actions' => 'integer',
            'actions_taken' => 'integer',
            'reauth_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'panic_stopped_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AuthDevice, $this> */
    public function authDevice(): BelongsTo
    {
        return $this->belongsTo(AuthDevice::class, 'auth_device_id');
    }

    /** @return HasMany<ActionProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(ActionProposal::class, 'browser_session_id');
    }

    /**
     * The row as the §3 `ComputerUseProvider` contract sees it, so the provider never
     * needs a second read to know its bounds.
     */
    public function toSession(): AutomationSession
    {
        return new AutomationSession(
            id: (string) $this->getKey(),
            organizationId: $this->organization_id,
            userId: $this->user_id,
            grantId: $this->grant_id,
            allowlist: array_map(
                fn (array $entry): AllowlistEntry => new AllowlistEntry(
                    bundleId: (string) $entry['bundle_id'],
                    windowTitlePattern: isset($entry['window_title_pattern'])
                        ? (string) $entry['window_title_pattern']
                        : null,
                ),
                $this->allowlist,
            ),
            state: $this->state,
            startedAt: new DateTimeImmutable($this->started_at->toIso8601String()),
            expiresAt: new DateTimeImmutable($this->expires_at->toIso8601String()),
            idleTimeoutSeconds: $this->idle_timeout_seconds,
            maxActions: $this->max_actions,
            actionsTaken: $this->actions_taken,
        );
    }

    /**
     * ADR-005 D1.8: the idle timeout is the third bound beside `expires_at` and
     * `max_actions`, and the one `AutomationSession::isLive()` cannot judge on its own
     * because it does not carry `last_activity_at`.
     */
    public function isIdleTimedOut(CarbonImmutable $now): bool
    {
        $last = $this->last_activity_at ?? $this->started_at;

        return $last->addSeconds($this->idle_timeout_seconds) <= $now;
    }
}
