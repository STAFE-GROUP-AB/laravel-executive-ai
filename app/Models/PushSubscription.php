<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BusinessObject;
use App\Models\Concerns\EncryptsSensitiveAttributes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One browser that opted in to Web Push (RFC 8030/8291/8292), ADR-006 through
 * ADR-002 **D9.5**. The table landed with S2-03; this model exists because S2-04 owns
 * the `encrypted:` cast the migration for that table says is "**S2-04's** (#87) to
 * add", and a cast needs a model to live on.
 *
 * It is deliberately thin. Nothing writes these rows yet — the subscribe, deliver and
 * prune paths are ADR-006's own items — so this carries the §3.1 casts, the user
 * relation, the one scope every one of those paths will need, and nothing speculative.
 *
 * **`p256dh` and `auth` are encrypted at rest.** They are the subscription's own key
 * material: the client public key and the auth secret RFC 8291 payload encryption
 * uses. ADR-003 D1's "no column may hold a token, password, key or verifier" does not
 * send them to the Keychain — they are the *browser's* values, handed to us, and the
 * **VAPID private key** is the Keychain item (`se.stafe.executive-ai.webpush`) — but
 * they are the secret half of a subscription, so the row is `sensitivity_class =
 * confidential` and the two columns are encrypted. Neither is matched by anything:
 * the natural key is `endpoint` (`push_subscriptions_endpoint_unique`), which stays
 * plaintext precisely because re-subscribing the same browser has to find the
 * existing row rather than insert a second one.
 *
 * @property string $id
 * @property ?string $organization_id
 * @property string $user_id
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 * @property string $content_encoding
 * @property ?string $device_label
 * @property ?string $rp_id
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $last_seen
 * @property ?CarbonImmutable $last_delivery_at
 * @property int $failure_count
 * @property ?CarbonImmutable $revoked_at
 * @property ?string $revoked_reason
 * @property ?CarbonImmutable $deleted_at
 */
#[Hidden(['p256dh', 'auth', 'endpoint'])]
class PushSubscription extends Model
{
    use BusinessObject;
    use EncryptsSensitiveAttributes;

    // The table carries `deleted_at` (`BusinessObjectColumns::timestamps()`), so the
    // trait has to be here: without it `delete()` is a hard delete, and a hard delete
    // takes the row's §3.1 retention evidence with it. The row is normally *stamped*
    // rather than removed — `revoked_at` when the push service reports the
    // subscription gone — and the `push_devices` retention rule is what eventually
    // prunes it.
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            ...$this->encryptedAttributeCasts(),
            'expires_at' => 'immutable_datetime',
            'last_seen' => 'immutable_datetime',
            'last_delivery_at' => 'immutable_datetime',
            'failure_count' => 'integer',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The subscriptions a delivery may be sent to: not revoked and not expired.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDeliverable(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $instant = $now ?? CarbonImmutable::now();

        return $query->whereNull('revoked_at')
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $instant));
    }
}
