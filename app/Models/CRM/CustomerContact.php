<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Models\Concerns\BusinessObject;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §3.1 `customer_contact`: a business object in its own right (ULID, provenance,
 * soft delete), so attach rows through this model rather than `attach()`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string $contact_id
 * @property ?string $role
 */
class CustomerContact extends Pivot
{
    use BusinessObject;
    use SoftDeletes;

    protected $table = 'customer_contact';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return $this->businessObjectCasts();
    }
}
