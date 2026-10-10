<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Data\SensitivityClass;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Casts for the §3.1 common columns added by BusinessObjectColumns::lineage().
 * Models combine it with SoftDeletes where the table has `deleted_at`.
 */
trait BusinessObject
{
    use HasUlids;

    /** @return array<string, string> */
    protected function businessObjectCasts(): array
    {
        return [
            'sensitivity_class' => SensitivityClass::class,
            'provenance' => 'array',
            'retain_until' => 'immutable_datetime',
        ];
    }
}
