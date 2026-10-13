<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\CRM\Customers\CustomerStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer with a legal identity (blueprint §3.2). `email_domains` is a hint
 * for the matcher, never a key: two customers may share a domain (§5.6).
 *
 * @property string $legal_name
 * @property string $display_name
 * @property string $country
 * @property ?string $registration_number
 * @property list<string> $email_domains
 * @property ?string $website
 * @property CustomerStatus $status
 * @property ?string $owner_user_id
 * @property list<string> $tags
 * @property string $notes
 */
class Customer extends CrmModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'email_domains' => 'array',
            'tags' => 'array',
            'status' => CustomerStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsToMany<Contact, $this, CustomerContact> */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'customer_contact')
            ->using(CustomerContact::class)
            ->withPivot(['id', 'organization_id', 'role'])
            ->wherePivotNull('deleted_at');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Communication, $this> */
    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class)->orderByDesc('occurred_at');
    }

    /** @return HasMany<Commitment, $this> */
    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    public function sharesEmailDomainWith(Customer $other): bool
    {
        return array_intersect($this->email_domains, $other->email_domains) !== [];
    }
}
