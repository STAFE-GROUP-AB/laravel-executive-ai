<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered passkey (S2-05, docs/architecture/auth.md §7). The stored
 * public key is not a secret, but the credential id and the key are still
 * hidden from serialisation so a dump of the model never carries them into a
 * log line or a diagnostics export by accident.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $credential_id base64url, unpadded
 * @property string $public_key base64 of the COSE key bytes
 * @property string $user_handle
 * @property int $sign_count
 * @property string $aaguid
 * @property list<string> $transports
 * @property string $attestation_type
 * @property array<string, mixed> $trust_path
 * @property ?bool $backup_eligible
 * @property ?bool $backed_up
 * @property ?bool $uv_initialized
 * @property string $rp_id
 * @property string $origin
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
#[Hidden(['credential_id', 'public_key', 'user_handle', 'trust_path'])]
class PasskeyCredential extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'transports' => 'array',
            'trust_path' => 'array',
            'backup_eligible' => 'boolean',
            'backed_up' => 'boolean',
            'uv_initialized' => 'boolean',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
