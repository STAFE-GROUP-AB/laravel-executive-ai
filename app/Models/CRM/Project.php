<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\CRM\Projects\ProjectStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $customer_id
 * @property string $name
 * @property ProjectStatus $status
 * @property ?CarbonImmutable $starts_on
 * @property ?CarbonImmutable $due_on
 * @property string $description
 */
class Project extends CrmModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'status' => ProjectStatus::class,
            'starts_on' => 'immutable_date',
            'due_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<ProjectLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class)->orderBy('position');
    }

    /** @return BelongsToMany<Contact, $this, ProjectContact> */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'project_contact')
            ->using(ProjectContact::class)
            ->withPivot(['id', 'organization_id', 'role'])
            ->wherePivotNull('deleted_at');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
