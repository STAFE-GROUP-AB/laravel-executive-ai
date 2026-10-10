<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Install\InstallState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per install-state transition; the newest row is the marker.
 *
 * @property string $id
 * @property InstallState $state
 * @property string $app_version
 * @property ?string $reason
 * @property CarbonImmutable $changed_at
 */
class InstallStateTransition extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'install_state';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => InstallState::class,
            'changed_at' => 'immutable_datetime',
        ];
    }
}
