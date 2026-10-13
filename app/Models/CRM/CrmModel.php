<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Models\Concerns\BusinessObject;
use App\Models\Organization;
use App\Support\Data\SensitivityClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Base for the §3.1 CRM tables: the common columns `BusinessObjectColumns` adds
 * (`App\Support\Database\BusinessObjectColumns`), their casts and the tenant
 * relation, declared once so every CRM model carries the same shape.
 *
 * Pivot rows (`customer_contact`, `project_contact`) are business objects too but
 * extend Eloquent's Pivot; they use the same trait.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $source_id
 * @property ?string $source_uri
 * @property SensitivityClass $sensitivity_class
 * @property array<string, mixed> $provenance
 * @property ?string $retention_policy
 * @property ?CarbonImmutable $retain_until
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
abstract class CrmModel extends Model
{
    use BusinessObject;
    use SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return $this->businessObjectCasts();
    }
}
