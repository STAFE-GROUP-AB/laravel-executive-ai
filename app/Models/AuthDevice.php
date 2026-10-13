<?php

declare(strict_types=1);

namespace App\Models;

use App\Security\Authorization\Devices\LoginMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in and the browser it came from (S2-05, docs/architecture/auth.md
 * §5). `session_id` links the row to the `sessions` table, which decides
 * whether the device is still signed in; `revoked_at` records that the owner
 * ended it from the Devices page or by signing out everywhere else.
 *
 * @property string $id
 * @property string $user_id
 * @property string $session_id
 * @property LoginMethod $method
 * @property ?string $passkey_credential_id
 * @property string $label
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property CarbonImmutable $signed_in_at
 * @property ?CarbonImmutable $revoked_at
 * @property ?string $revoked_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
#[Hidden(['session_id'])]
class AuthDevice extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => LoginMethod::class,
            'signed_in_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PasskeyCredential, $this> */
    public function passkey(): BelongsTo
    {
        return $this->belongsTo(PasskeyCredential::class, 'passkey_credential_id');
    }
}
