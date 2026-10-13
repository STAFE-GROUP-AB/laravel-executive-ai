<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Briefs\Priority;
use App\CRM\Tasks\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A todo (§3.2). `due_at` is null unless a source or the user stated a deadline (§4.2).
 *
 * @property string $title
 * @property string $description
 * @property TaskStatus $status
 * @property Priority $priority
 * @property ?CarbonImmutable $due_at
 * @property ?string $customer_id
 * @property ?string $project_id
 * @property ?string $contact_id
 */
class Task extends CrmModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'status' => TaskStatus::class,
            'priority' => Priority::class,
            'due_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<TaskAssignee, $this> */
    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')
            ->wherePivotNull('deleted_at');
    }

    /** @return HasMany<Commitment, $this> */
    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class, 'follow_up_task_id');
    }
}
