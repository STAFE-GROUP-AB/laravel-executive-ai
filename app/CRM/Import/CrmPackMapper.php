<?php

declare(strict_types=1);

namespace App\CRM\Import;

use App\Models\CRM\Commitment;
use App\Models\CRM\Communication;
use App\Models\CRM\Contact;
use App\Models\CRM\ContactEmail;
use App\Models\CRM\CrmModel;
use App\Models\CRM\Customer;
use App\Models\CRM\CustomerContact;
use App\Models\CRM\Project;
use App\Models\CRM\ProjectContact;
use App\Models\CRM\ProjectLink;
use App\Models\CRM\Task;
use App\Models\CRM\TaskAssignee;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;

/**
 * Reads and writes the CRM pack shape of `packaging/fixtures/crm/*.json`
 * (`crm.schema.json` 1.0.0) against the §3.1 tables: the JSON half of §3.2's
 * "Import/export CSV/JSON" and the oracle the round-trip test uses.
 *
 * Two deliberate normalizations, both from ADR-002 D10:
 * - **timestamps are UTC.** The pack writes RFC 3339 with a local offset and
 *   carries no `utc_offset` of its own (unlike the brief schema), so an instant
 *   round-trips as an instant and `export()` renders it in UTC.
 * - **id lists are ordered by id.** `customer_ids`, `contact_ids` and
 *   `assignee_user_ids` are sets in the schema (`uniqueItems`, no semantic
 *   order); `emails` and `github_links` keep their order through a `position`
 *   column because that order is shown to a person.
 *
 * Writes run in one transaction per `import()` (ADR-002 D3.2) and perform no
 * network I/O. External text — notes, subjects, excerpts, promises — is stored
 * as data and never interpreted.
 */
final class CrmPackMapper
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $pack  keyed by file basename
     */
    public function import(array $pack): void
    {
        $this->connections->connection()->transaction(function () use ($pack): void {
            foreach ($pack['customers'] ?? [] as $record) {
                $this->importCustomer($record);
            }

            foreach ($pack['contacts'] ?? [] as $record) {
                $this->importContact($record);
            }

            foreach ($pack['projects'] ?? [] as $record) {
                $this->importProject($record);
            }

            foreach ($pack['tasks'] ?? [] as $record) {
                $this->importTask($record);
            }

            foreach ($pack['communications'] ?? [] as $record) {
                $this->importCommunication($record);
            }

            foreach ($pack['commitments'] ?? [] as $record) {
                $this->importCommitment($record);
            }
        });
    }

    /**
     * The pack as stored, every entity ordered by id so both database profiles
     * return it identically (ADR-002 D10).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function export(string $organizationId): array
    {
        return [
            'customers' => $this->exportCustomers($organizationId),
            'contacts' => $this->exportContacts($organizationId),
            'projects' => $this->exportProjects($organizationId),
            'tasks' => $this->exportTasks($organizationId),
            'communications' => $this->exportCommunications($organizationId),
            'commitments' => $this->exportCommitments($organizationId),
        ];
    }

    /** @param array<string, mixed> $record */
    private function importCustomer(array $record): void
    {
        Customer::query()->create([
            ...$this->common($record),
            'legal_name' => $record['legal_name'],
            'display_name' => $record['display_name'],
            'country' => $record['country'],
            'registration_number' => $record['registration_number'],
            'email_domains' => $record['email_domains'],
            'website' => $record['website'],
            'status' => $record['status'],
            'owner_user_id' => $record['owner_user_id'],
            'tags' => $record['tags'],
            'notes' => $record['notes'],
        ]);
    }

    /** @param array<string, mixed> $record */
    private function importContact(array $record): void
    {
        Contact::query()->create([
            ...$this->common($record),
            'given_name' => $record['given_name'],
            'family_name' => $record['family_name'],
            'display_name' => $record['display_name'],
            'title' => $record['title'],
            'phones' => $record['phones'],
            'language' => $record['language'],
            'notes' => $record['notes'],
        ]);

        /** @var list<array{address: string, label: string, primary: bool}> $emails */
        $emails = $record['emails'];

        foreach ($emails as $position => $email) {
            ContactEmail::query()->create([
                ...$this->child($record),
                'contact_id' => $record['id'],
                'address' => $email['address'],
                'label' => $email['label'],
                'is_primary' => $email['primary'],
                'position' => $position,
            ]);
        }

        /** @var list<string> $customerIds */
        $customerIds = $record['customer_ids'];

        foreach ($customerIds as $customerId) {
            CustomerContact::query()->create([
                ...$this->child($record),
                'customer_id' => $customerId,
                'contact_id' => $record['id'],
                'role' => null,
            ]);
        }
    }

    /** @param array<string, mixed> $record */
    private function importProject(array $record): void
    {
        Project::query()->create([
            ...$this->common($record),
            'customer_id' => $record['customer_id'],
            'name' => $record['name'],
            'status' => $record['status'],
            'starts_on' => $record['starts_on'],
            'due_on' => $record['due_on'],
            'description' => $record['description'],
        ]);

        /** @var list<array{kind: string, uri: string}> $links */
        $links = $record['github_links'];

        foreach ($links as $position => $link) {
            ProjectLink::query()->create([
                ...$this->child($record),
                'project_id' => $record['id'],
                'kind' => $link['kind'],
                'uri' => $link['uri'],
                'label' => null,
                'position' => $position,
            ]);
        }

        /** @var list<string> $contactIds */
        $contactIds = $record['contact_ids'];

        foreach ($contactIds as $contactId) {
            ProjectContact::query()->create([
                ...$this->child($record),
                'project_id' => $record['id'],
                'contact_id' => $contactId,
                'role' => null,
            ]);
        }
    }

    /** @param array<string, mixed> $record */
    private function importTask(array $record): void
    {
        Task::query()->create([
            ...$this->common($record),
            'title' => $record['title'],
            'description' => $record['description'],
            'status' => $record['status'],
            'priority' => $record['priority'],
            'due_at' => $this->instant($record['due_at']),
            'customer_id' => $record['customer_id'],
            'project_id' => $record['project_id'],
            'contact_id' => $record['contact_id'],
        ]);

        /** @var list<string> $userIds */
        $userIds = $record['assignee_user_ids'];

        foreach ($userIds as $userId) {
            TaskAssignee::query()->create([
                ...$this->child($record),
                'task_id' => $record['id'],
                'user_id' => $userId,
            ]);
        }
    }

    /** @param array<string, mixed> $record */
    private function importCommunication(array $record): void
    {
        Communication::query()->create([
            ...$this->common($record),
            'source_type' => $record['source_type'],
            'account_id' => $record['account_id'],
            'account_label' => $record['account_label'],
            'direction' => $record['direction'],
            'occurred_at' => $this->instant($record['occurred_at']),
            'subject' => $record['subject'],
            'excerpt' => $record['excerpt'],
            'from_address' => $record['from_address'],
            'to_addresses' => $record['to_addresses'],
            'customer_id' => $record['customer_id'],
            'contact_id' => $record['contact_id'],
            'match_reason' => $record['match_reason'],
            'matched_by' => $record['matched_by'],
            'confidence' => $record['confidence'],
            'correction_of' => $record['correction_of'],
        ]);
    }

    /** @param array<string, mixed> $record */
    private function importCommitment(array $record): void
    {
        Commitment::query()->create([
            ...$this->common($record),
            'communication_id' => $record['communication_id'],
            'customer_id' => $record['customer_id'],
            'contact_id' => $record['contact_id'],
            'direction' => $record['direction'],
            'text' => $record['text'],
            'promised_at' => $this->instant($record['promised_at']),
            'due_at' => $this->instant($record['due_at']),
            'status' => $record['status'],
            'follow_up_task_id' => $record['follow_up_task_id'],
            'brief_item_id' => $record['brief_item_id'],
            'extracted_by' => $record['extracted_by'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function exportCustomers(string $organizationId): array
    {
        return Customer::query()->where('organization_id', $organizationId)->orderBy('id')->get()
            ->map(fn (Customer $customer): array => [
                ...$this->commonOf($customer),
                'legal_name' => $customer->legal_name,
                'display_name' => $customer->display_name,
                'country' => $customer->country,
                'registration_number' => $customer->registration_number,
                'email_domains' => $customer->email_domains,
                'website' => $customer->website,
                'status' => $customer->status->value,
                'owner_user_id' => $customer->owner_user_id,
                'tags' => $customer->tags,
                'notes' => $customer->notes,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportContacts(string $organizationId): array
    {
        return Contact::query()->with(['emails', 'customers'])->where('organization_id', $organizationId)->orderBy('id')->get()
            ->map(fn (Contact $contact): array => [
                ...$this->commonOf($contact),
                'customer_ids' => $contact->customers->pluck('id')->sort()->values()->all(),
                'given_name' => $contact->given_name,
                'family_name' => $contact->family_name,
                'display_name' => $contact->display_name,
                'title' => $contact->title,
                'emails' => $contact->emails->map(fn (ContactEmail $email): array => [
                    'address' => $email->address,
                    'label' => $email->label->value,
                    'primary' => $email->is_primary,
                ])->all(),
                'phones' => $contact->phones,
                'language' => $contact->language,
                'notes' => $contact->notes,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportProjects(string $organizationId): array
    {
        return Project::query()->with(['links', 'contacts'])->where('organization_id', $organizationId)->orderBy('id')->get()
            ->map(fn (Project $project): array => [
                ...$this->commonOf($project),
                'customer_id' => $project->customer_id,
                'name' => $project->name,
                'status' => $project->status->value,
                'starts_on' => $project->starts_on?->format('Y-m-d'),
                'due_on' => $project->due_on?->format('Y-m-d'),
                'contact_ids' => $project->contacts->pluck('id')->sort()->values()->all(),
                'github_links' => $project->links->map(fn (ProjectLink $link): array => [
                    'kind' => $link->kind->value,
                    'uri' => $link->uri,
                ])->all(),
                'description' => $project->description,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportTasks(string $organizationId): array
    {
        return Task::query()->with('assignees')->where('organization_id', $organizationId)->orderBy('id')->get()
            ->map(fn (Task $task): array => [
                ...$this->commonOf($task),
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'due_at' => $this->rfc3339($task->due_at),
                'customer_id' => $task->customer_id,
                'project_id' => $task->project_id,
                'contact_id' => $task->contact_id,
                'assignee_user_ids' => $task->assignees->pluck('user_id')->sort()->values()->all(),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportCommunications(string $organizationId): array
    {
        return Communication::query()->where('organization_id', $organizationId)->orderBy('id')->get()
            ->map(fn (Communication $communication): array => [
                ...$this->commonOf($communication),
                'source_type' => $communication->source_type->value,
                'account_id' => $communication->account_id,
                'account_label' => $communication->account_label,
                'direction' => $communication->direction->value,
                'occurred_at' => $this->rfc3339($communication->occurred_at),
                'subject' => $communication->subject,
                'excerpt' => $communication->excerpt,
                'from_address' => $communication->from_address,
                'to_addresses' => $communication->to_addresses,
                'customer_id' => $communication->customer_id,
                'contact_id' => $communication->contact_id,
                'match_reason' => $communication->match_reason,
                'matched_by' => $communication->matched_by->value,
                'confidence' => $communication->confidence,
                'correction_of' => $communication->correction_of,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportCommitments(string $organizationId): array
    {
        return Commitment::query()->where('organization_id', $organizationId)->orderBy('id')->get()
            ->map(fn (Commitment $commitment): array => [
                ...$this->commonOf($commitment),
                'communication_id' => $commitment->communication_id,
                'customer_id' => $commitment->customer_id,
                'contact_id' => $commitment->contact_id,
                'direction' => $commitment->direction->value,
                'text' => $commitment->text,
                'promised_at' => $this->rfc3339($commitment->promised_at),
                'due_at' => $this->rfc3339($commitment->due_at),
                'status' => $commitment->status->value,
                'follow_up_task_id' => $commitment->follow_up_task_id,
                'brief_item_id' => $commitment->brief_item_id,
                'extracted_by' => $commitment->extracted_by->value,
            ])->all();
    }

    /**
     * The §3.1 common columns as the pack writes them.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function common(array $record): array
    {
        return [
            'id' => $record['id'],
            'organization_id' => $record['organization_id'],
            'created_at' => $this->instant($record['created_at']),
            'updated_at' => $this->instant($record['updated_at']),
            'deleted_at' => $this->instant($record['deleted_at']),
            'source_id' => $record['source_id'],
            'source_uri' => $record['source_uri'],
            'sensitivity_class' => $record['sensitivity_class'],
            'provenance' => $record['provenance'],
        ];
    }

    /**
     * A row the pack models as a nested array (an email, a link, a pivot): it
     * inherits the parent's tenancy, lineage and provenance, and gets its own ULID.
     *
     * @param  array<string, mixed>  $parent
     * @return array<string, mixed>
     */
    private function child(array $parent): array
    {
        return [
            ...$this->common($parent),
            'id' => (string) Str::ulid(),
        ];
    }

    /** @return array<string, mixed> */
    private function commonOf(CrmModel $model): array
    {
        return [
            'id' => $model->id,
            'organization_id' => $model->organization_id,
            'created_at' => $this->rfc3339($model->created_at),
            'updated_at' => $this->rfc3339($model->updated_at),
            'deleted_at' => $this->rfc3339($model->deleted_at),
            'source_id' => $model->source_id,
            'source_uri' => $model->source_uri,
            'sensitivity_class' => $model->sensitivity_class->value,
            'provenance' => $model->provenance,
        ];
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }

    private function rfc3339(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::instance($value)->utc()->toRfc3339String();
    }
}
