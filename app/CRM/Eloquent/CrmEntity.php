<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\CRM\Search\SearchableEntity;
use App\Models\CRM\Contact;
use App\Models\CRM\CrmModel;
use App\Models\CRM\Customer;
use App\Models\CRM\Project;
use App\Models\CRM\Task;
use App\Support\Data\SensitivityClass;

/**
 * The four §3.2 record types the CRM screens and the repository work with, and
 * everything that differs between them stated once.
 *
 * It exists so the rest of this namespace can be written **generically**: the
 * tenant predicate, the pagination, the retention stamp and the deletion flow
 * are one implementation each rather than four, and `WorkspaceQueries::of()`
 * is the only place a CRM query is built. A fifth record type is a case here
 * plus its form, not a fifth copy of the data layer.
 *
 * `corpus()` is the half worth reading twice. ADR-002 D4 indexes three
 * entities — `customers`, `contacts` and `communications` — and only
 * `string`/`text` columns on them, because InnoDB and FTS5 have to cover
 * exactly the same text for the `search` contract group to demand the same hit
 * set. `projects` and `tasks` have no index of their own, so a term typed on
 * those screens is resolved through the **customers** corpus and matched
 * against `customer_id`: the one piece of indexed text that relates to them.
 * The alternative would be `LIKE '%…%'` on `name`, which is neither the same
 * query on both profiles (collation, diacritics) nor indexed at all.
 */
enum CrmEntity: string
{
    case Customers = 'customers';
    case Contacts = 'contacts';
    case Projects = 'projects';
    case Tasks = 'tasks';

    public function table(): string
    {
        return $this->value;
    }

    public function newModel(): CrmModel
    {
        return match ($this) {
            self::Customers => new Customer,
            self::Contacts => new Contact,
            self::Projects => new Project,
            self::Tasks => new Task,
        };
    }

    /** The full-text corpus a term typed on this entity is resolved through (ADR-002 D4). */
    public function corpus(): SearchableEntity
    {
        return match ($this) {
            self::Customers => SearchableEntity::Customers,
            self::Contacts => SearchableEntity::Contacts,
            self::Projects, self::Tasks => SearchableEntity::Customers,
        };
    }

    /**
     * The column the corpus's hit ids are matched against: the row's own `id`
     * where the entity is indexed, and `customer_id` where it is not.
     */
    public function corpusColumn(): string
    {
        return match ($this) {
            self::Customers, self::Contacts => 'id',
            self::Projects, self::Tasks => 'customer_id',
        };
    }

    /**
     * §3.1 `sensitivity_class` for a row this application creates. A contact is
     * a natural person and is always `personal`; the column default on
     * `contacts` is the generic `internal`, so the value is stated here rather
     * than left to the schema (S2-03, data-model §1.1).
     */
    public function sensitivityClass(): SensitivityClass
    {
        return match ($this) {
            self::Contacts => SensitivityClass::Personal,
            self::Customers, self::Projects, self::Tasks => SensitivityClass::Internal,
        };
    }

    /** The §1.2 route name for this entity's screen. */
    public function routeName(): string
    {
        return "crm.{$this->value}";
    }

    /** Whether the table carries a `status` column a list screen can filter on. */
    public function hasStatus(): bool
    {
        return $this !== self::Contacts;
    }

    /**
     * Whether the table carries `customer_id`. A contact's customers are a
     * many-to-many through `customer_contact` (§3.2: one person can belong to
     * more than one customer), so a contact has no such column.
     */
    public function hasCustomerColumn(): bool
    {
        return $this === self::Projects || $this === self::Tasks;
    }

    /** The column a list screen orders and labels rows by. */
    public function displayColumn(): string
    {
        return match ($this) {
            self::Customers, self::Contacts => 'display_name',
            self::Projects => 'name',
            self::Tasks => 'title',
        };
    }
}
