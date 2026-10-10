<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BusinessObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit event (§10, §11). `context` is redacted before insert.
 *
 * @property string $id
 * @property ?string $organization_id
 * @property string $actor
 * @property string $action
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property string $outcome
 * @property ?array<string, mixed> $context
 * @property CarbonImmutable $occurred_at
 */
class AuditEvent extends Model
{
    use BusinessObject;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
