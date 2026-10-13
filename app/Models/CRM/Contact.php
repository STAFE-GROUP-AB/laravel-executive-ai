<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Models\Concerns\EncryptsSensitiveAttributes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A natural person at one or more customers (blueprint §3.2). Always
 * `sensitivity_class = personal`: subject to GDPR export and erasure.
 *
 * `phones` is **encrypted at rest** (S2-04): personal data that nothing matches on,
 * so the cast costs no query. The five name and note columns are deliberately *not*
 * encrypted — they are the `contacts` full-text index (`SearchableEntity::Contacts`)
 * and ciphertext tokenises to noise; they stay plaintext with the row's
 * `sensitivity_class` recorded instead (docs/architecture/data-model.md
 * §"Encryption at rest").
 *
 * @property string $given_name
 * @property string $family_name
 * @property string $display_name
 * @property ?string $title
 * @property list<array{number: string, label: string}> $phones
 * @property string $language
 * @property string $notes
 */
class Contact extends CrmModel
{
    use EncryptsSensitiveAttributes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            ...$this->encryptedAttributeCasts(),
        ];
    }

    /** @return HasMany<ContactEmail, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class)->orderBy('position');
    }

    /** @return BelongsToMany<Customer, $this, CustomerContact> */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_contact')
            ->using(CustomerContact::class)
            ->withPivot(['id', 'organization_id', 'role'])
            ->wherePivotNull('deleted_at');
    }

    /** @return BelongsToMany<Project, $this, ProjectContact> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_contact')
            ->using(ProjectContact::class)
            ->withPivot(['id', 'organization_id', 'role'])
            ->wherePivotNull('deleted_at');
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
}
