<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One issued login code. Holds the HMAC of the code, never the code. A row is
 * "active" while it is unconsumed and unexpired; issuing a new code expires
 * the previous active ones, verifying consumes exactly one.
 *
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $consumed_at
 * @property int $attempts
 * @property ?string $ip_address
 * @property ?CarbonImmutable $created_at
 */
#[Hidden(['code_hash'])]
class OtpCode extends Model
{
    use HasUlids;
    use Prunable;

    public const null UPDATED_AT = null;

    protected $table = 'otp_codes';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query, ?CarbonImmutable $now = null): void
    {
        $query->whereNull('consumed_at')->where('expires_at', '>', $now ?? CarbonImmutable::now('UTC'));
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expires_at <= ($now ?? CarbonImmutable::now('UTC'));
    }

    public function isLockedOut(int $maxAttempts): bool
    {
        return $this->attempts >= $maxAttempts;
    }

    /** Expired codes are kept one day for the audit trail, then pruned by `model:prune`. */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', CarbonImmutable::now('UTC')->subDay());
    }
}
