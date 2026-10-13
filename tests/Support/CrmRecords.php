<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Briefs\Priority;
use App\CRM\Contacts\ContactEmailLabel;
use App\CRM\Customers\CustomerStatus;
use App\CRM\Eloquent\RetentionStamp;
use App\CRM\Projects\ProjectStatus;
use App\CRM\Tasks\TaskStatus;
use App\Models\CRM\Contact;
use App\Models\CRM\ContactEmail;
use App\Models\CRM\CrmModel;
use App\Models\CRM\Customer;
use App\Models\CRM\CustomerContact;
use App\Models\CRM\Project;
use App\Models\CRM\Task;
use App\Models\Organization;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Data\SensitivityClass;
use Carbon\CarbonImmutable;

/**
 * CRM rows for the S2-09 feature tests.
 *
 * Deliberately *not* the frozen S0-18 pack: `CrmFixturePack` is the oracle for
 * the schema and the search corpus, with fixed ULIDs and a workspace of its
 * own, while these tests need a handful of rows in **their** organization so a
 * cross-tenant case can be stated in one line. The contract test
 * (`WorkspaceRepositoryContractTest`) uses the pack, because that is where
 * "identical ids on both profiles" has to be measured.
 *
 * Every row carries the §3.1 lineage the application writes — provenance,
 * sensitivity class, and the retention columns from the row's own rule — so a
 * test row is shaped like a real one and a retention assertion means something.
 */
final class CrmRecords
{
    public static function customer(Organization $organization, string $name = 'Nordlund Logistik', array $attributes = []): Customer
    {
        $customer = new Customer;
        $customer->forceFill([
            ...self::lineage($organization, SensitivityClass::Internal),
            'legal_name' => $name.' AB',
            'display_name' => $name,
            'country' => 'SE',
            'registration_number' => null,
            'email_domains' => [],
            'website' => null,
            'status' => CustomerStatus::Active,
            'owner_user_id' => null,
            'tags' => [],
            'notes' => '',
            ...$attributes,
        ]);

        return self::stamped($customer);
    }

    public static function contact(Organization $organization, string $name = 'Elin Sjöström', array $attributes = []): Contact
    {
        $parts = explode(' ', $name, 2);

        $contact = new Contact;
        $contact->forceFill([
            ...self::lineage($organization, SensitivityClass::Personal),
            'given_name' => $parts[0],
            'family_name' => $parts[1] ?? '',
            'display_name' => $name,
            'title' => null,
            'phones' => [],
            'language' => 'sv',
            'notes' => '',
            ...$attributes,
        ]);

        return self::stamped($contact);
    }

    public static function address(Contact $contact, string $address, bool $primary = true, int $position = 0): ContactEmail
    {
        $email = new ContactEmail;
        $email->forceFill([
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->getKey(),
            'address' => mb_strtolower($address),
            'label' => ContactEmailLabel::Work,
            'is_primary' => $primary,
            'position' => $position,
            'sensitivity_class' => SensitivityClass::Personal,
            'provenance' => Provenance::job('CrmRecords', ProvenanceOrigin::Import),
        ]);

        return self::stamped($email);
    }

    public static function link(Customer $customer, Contact $contact): CustomerContact
    {
        $link = new CustomerContact;
        $link->forceFill([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->getKey(),
            'contact_id' => $contact->getKey(),
            'role' => null,
            'sensitivity_class' => SensitivityClass::Personal,
            'provenance' => Provenance::job('CrmRecords', ProvenanceOrigin::Import),
        ]);
        RetentionStamp::apply($link, CarbonImmutable::now());
        $link->save();

        return $link;
    }

    public static function project(Customer $customer, string $name = 'Lagerlokal Mölndal', array $attributes = []): Project
    {
        $project = new Project;
        $project->forceFill([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->getKey(),
            'name' => $name,
            'status' => ProjectStatus::Active,
            'starts_on' => null,
            'due_on' => null,
            'description' => '',
            'sensitivity_class' => SensitivityClass::Internal,
            'provenance' => Provenance::job('CrmRecords', ProvenanceOrigin::Import),
            ...$attributes,
        ]);

        return self::stamped($project);
    }

    public static function task(Organization $organization, string $title = 'Skicka offert', array $attributes = []): Task
    {
        $task = new Task;
        $task->forceFill([
            ...self::lineage($organization, SensitivityClass::Internal),
            'title' => $title,
            'description' => '',
            'status' => TaskStatus::Todo,
            'priority' => Priority::Normal,
            'due_at' => null,
            'customer_id' => null,
            'project_id' => null,
            'contact_id' => null,
            ...$attributes,
        ]);

        return self::stamped($task);
    }

    /** @return array<string, mixed> */
    private static function lineage(Organization $organization, SensitivityClass $sensitivityClass): array
    {
        return [
            'organization_id' => $organization->getKey(),
            'sensitivity_class' => $sensitivityClass,
            'provenance' => Provenance::job('CrmRecords', ProvenanceOrigin::Import),
        ];
    }

    /**
     * @template TModel of CrmModel
     *
     * @param  TModel  $row
     * @return TModel
     */
    private static function stamped(CrmModel $row): CrmModel
    {
        RetentionStamp::apply($row, CarbonImmutable::now());
        $row->save();

        return $row;
    }
}
