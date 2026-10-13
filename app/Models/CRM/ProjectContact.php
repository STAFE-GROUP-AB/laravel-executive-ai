<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Models\Concerns\BusinessObject;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contacts involved in a project; see CustomerContact for why it is a full
 * business object.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $contact_id
 * @property ?string $role
 */
class ProjectContact extends Pivot
{
    use BusinessObject;
    use SoftDeletes;

    protected $table = 'project_contact';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return $this->businessObjectCasts();
    }
}
