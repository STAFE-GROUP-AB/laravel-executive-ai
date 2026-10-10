<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BusinessObject;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $language
 */
class Organization extends Model
{
    use BusinessObject;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return $this->businessObjectCasts();
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Brief, $this> */
    public function briefs(): HasMany
    {
        return $this->hasMany(Brief::class);
    }
}
