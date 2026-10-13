<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\CRM\Communications\ActivityEntry;
use App\CRM\Communications\ActivitySource;
use App\CRM\Communications\CommunicationSourceType;
use App\CRM\Contacts\ContactSummary;
use App\CRM\Customers\CustomerSummary;
use App\CRM\Projects\ProjectStatus;
use App\CRM\Projects\ProjectSummary;
use App\CRM\Queries\ActivityQuery;
use App\CRM\Queries\ContactQuery;
use App\CRM\Queries\CustomerQuery;
use App\CRM\Queries\ProjectQuery;
use App\CRM\Queries\QueryPage;
use App\CRM\Queries\TaskQuery;
use App\CRM\Tasks\TaskStatus;
use App\CRM\Tasks\TaskSummary;
use App\CRM\WorkspaceRepository;
use App\CRM\WorkspaceScope;
use App\Models\CRM\Communication;
use App\Models\CRM\Contact;
use App\Models\CRM\CrmModel;
use App\Models\CRM\Customer;
use App\Models\CRM\Project;
use App\Models\CRM\Task;
use App\Support\Cancellation\CancellationToken;
use Carbon\CarbonInterface;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The §3 `WorkspaceRepository` over the S2-01 schema: the read API the brief
 * generator, the agent tools and the `crm.*` MCP tools share.
 *
 * Four properties the contract demands and this class provides:
 *
 * - **Scoped before it is built.** Every method obtains its builder from
 *   `WorkspaceQueries::of()`, which refuses a scope without a membership and
 *   applies the `organization_id` predicate itself. There is no `where` on a
 *   tenant column anywhere in this file, which is the point: forgetting one is
 *   not a mistake that can be made here (`docs/architecture/crm.md` §3).
 * - **Read models, not Eloquent.** Consumers see `CustomerSummary` and friends,
 *   so a provider-neutral shape crosses the module boundary and both ADR-002 D1
 *   profiles answer with the same fields.
 * - **One read transaction per call**, so a page is a consistent snapshot even
 *   while a sync job is writing (ADR-002 D3).
 * - **Ordered by `id` ascending and bounded by the page**, so both profiles
 *   return identical ids for the frozen fixture (ADR-002 D10, the `repository`
 *   contract group). `timeline()` is the documented exception: newest first,
 *   then by id, as the contract states.
 *
 * The repository stays **read-only** (module-contracts.md §3, "CRM writes —
 * mutations go through action proposals and approvals"). The screens write
 * through `CrmRepository`, and a deletion goes through `CrmDeletions`.
 *
 * Cancellation is checked before the query runs and again before the rows are
 * projected — the two points where unwinding costs nothing. A single bounded
 * `select` has no safe point inside it.
 */
final class EloquentWorkspaceRepository implements WorkspaceRepository
{
    public function __construct(
        private readonly DatabaseManager $connections,
        private readonly WorkspaceQueries $queries,
    ) {}

    /** @return list<CustomerSummary> */
    public function customers(CustomerQuery $query, CancellationToken $cancellation): array
    {
        $ownerUserId = $query->ownerUserId;

        return $this->read(
            $cancellation,
            fn (): Builder => $this->narrowed(
                CrmEntity::Customers,
                $query->scope,
                $query->search,
                $query->ids,
                $query->updatedSince,
                $query->page,
                static function (Builder $builder) use ($ownerUserId): void {
                    if ($ownerUserId !== null) {
                        $builder->where('customers.owner_user_id', $ownerUserId);
                    }
                },
            ),
            fn (Model $customer): CustomerSummary => $this->customerSummary($customer),
        );
    }

    /** @return list<ContactSummary> */
    public function contacts(ContactQuery $query, CancellationToken $cancellation): array
    {
        $customerId = $query->customerId;

        // §3.2: a contact has several addresses and any of them identifies the
        // person — this is the lookup §4.1's `ResolveIdentity` uses. It goes
        // through `ContactAddresses`, the module's only call site for matching
        // on an address, because S2-04 (#87) encrypts the column and moves the
        // index to `address_hash`; see that class.
        $addressContactIds = $query->emailAddress === null
            ? null
            : ContactAddresses::contactIdsFor($this->queries->organizationFor($query->scope), $query->emailAddress);

        return $this->read(
            $cancellation,
            fn (): Builder => $this->narrowed(
                CrmEntity::Contacts,
                $query->scope,
                $query->search,
                $query->ids,
                $query->updatedSince,
                $query->page,
                static function (Builder $builder) use ($customerId, $addressContactIds): void {
                    $builder->with(['emails', 'customers']);

                    if ($customerId !== null) {
                        $builder->whereHas('customers', static fn (Builder $customers): Builder => $customers->whereKey($customerId));
                    }

                    if ($addressContactIds !== null) {
                        $builder->whereIn('contacts.id', $addressContactIds);
                    }
                },
            ),
            fn (Model $contact): ContactSummary => $this->contactSummary($contact),
        );
    }

    /** @return list<ProjectSummary> */
    public function projects(ProjectQuery $query, CancellationToken $cancellation): array
    {
        $customerId = $query->customerId;
        $openOnly = $query->openOnly;
        $dueBefore = $query->dueBefore;

        return $this->read(
            $cancellation,
            fn (): Builder => $this->narrowed(
                CrmEntity::Projects,
                $query->scope,
                $query->search,
                $query->ids,
                $query->updatedSince,
                $query->page,
                static function (Builder $builder) use ($customerId, $openOnly, $dueBefore): void {
                    if ($customerId !== null) {
                        $builder->where('projects.customer_id', $customerId);
                    }

                    if ($openOnly) {
                        $builder->whereIn('projects.status', self::openProjectStatuses());
                    }

                    if ($dueBefore !== null) {
                        $builder->whereNotNull('projects.due_on')->where('projects.due_on', '<', $dueBefore);
                    }
                },
            ),
            fn (Model $project): ProjectSummary => $this->projectSummary($project),
        );
    }

    /** @return list<TaskSummary> */
    public function tasks(TaskQuery $query, CancellationToken $cancellation): array
    {
        $customerId = $query->customerId;
        $projectId = $query->projectId;
        $assigneeUserId = $query->assigneeUserId;
        $openOnly = $query->openOnly;
        $dueBefore = $query->dueBefore;

        return $this->read(
            $cancellation,
            fn (): Builder => $this->narrowed(
                CrmEntity::Tasks,
                $query->scope,
                $query->search,
                $query->ids,
                $query->updatedSince,
                $query->page,
                static function (Builder $builder) use ($customerId, $projectId, $assigneeUserId, $openOnly, $dueBefore): void {
                    $builder->with('assignees');

                    if ($customerId !== null) {
                        $builder->where('tasks.customer_id', $customerId);
                    }

                    if ($projectId !== null) {
                        $builder->where('tasks.project_id', $projectId);
                    }

                    if ($assigneeUserId !== null) {
                        $builder->whereHas('assignees', static fn (Builder $assignees): Builder => $assignees->where('user_id', $assigneeUserId));
                    }

                    if ($openOnly) {
                        $builder->whereIn('tasks.status', self::openTaskStatuses());
                    }

                    if ($dueBefore !== null) {
                        $builder->whereNotNull('tasks.due_at')->where('tasks.due_at', '<', $dueBefore);
                    }
                },
            ),
            fn (Model $task): TaskSummary => $this->taskSummary($task),
        );
    }

    /**
     * The activity timeline (ADR-002 D9), newest first. `ActivityQuery` refuses
     * to be constructed without an anchor, so a timeline read can never be
     * "every row in the workspace".
     *
     * @return list<ActivityEntry>
     */
    public function timeline(ActivityQuery $query, CancellationToken $cancellation): array
    {
        return $this->read(
            $cancellation,
            fn (): Builder => $this->timelineQuery($query),
            fn (Model $communication): ActivityEntry => $this->activityEntry($communication),
        );
    }

    /** @return list<string> the project statuses `ProjectQuery::$openOnly` keeps */
    public static function openProjectStatuses(): array
    {
        return [ProjectStatus::Planned->value, ProjectStatus::Active->value, ProjectStatus::OnHold->value];
    }

    /** @return list<string> the task statuses `TaskQuery::$openOnly` keeps */
    public static function openTaskStatuses(): array
    {
        return [TaskStatus::Todo->value, TaskStatus::Doing->value, TaskStatus::Blocked->value];
    }

    /** @return Builder<Communication> */
    private function timelineQuery(ActivityQuery $query): Builder
    {
        $builder = $this->queries->ofCommunications($query->scope);

        if ($query->customerId !== null) {
            $builder->where('communications.customer_id', $query->customerId);
        }

        if ($query->contactId !== null) {
            $builder->where('communications.contact_id', $query->contactId);
        }

        if ($query->projectId !== null) {
            // `communications` carries no project column — ADR-002 D9 links a
            // source item to a customer or a contact — so a project narrows to
            // its own customer's timeline, through a scoped subquery.
            $builder->whereIn(
                'communications.customer_id',
                $this->queries->of($query->scope, CrmEntity::Projects)
                    ->whereKey($query->projectId)
                    ->select('projects.customer_id'),
            );
        }

        if ($query->from !== null) {
            $builder->where('communications.occurred_at', '>=', $query->from);
        }

        if ($query->until !== null) {
            $builder->where('communications.occurred_at', '<=', $query->until);
        }

        if ($query->sources !== []) {
            $builder->whereIn('communications.source_type', self::sourceTypesFor($query->sources));
        }

        if ($query->page->after !== null) {
            $builder->where('communications.id', '<', $query->page->after);
        }

        return $builder
            ->orderByDesc('communications.occurred_at')
            ->orderByDesc('communications.id')
            ->limit($query->page->limit);
    }

    /**
     * The shared narrowing: the scoped builder, the corpus search, the id
     * filter, the `updated_since` watermark, the entity's own filters and the
     * keyset page — in that order, once, for all four record types.
     *
     * @param  list<string>  $ids
     * @param  Closure(Builder<CrmModel>): void  $extra
     * @return Builder<CrmModel>
     */
    private function narrowed(
        CrmEntity $entity,
        WorkspaceScope $scope,
        ?string $search,
        array $ids,
        ?DateTimeImmutable $updatedSince,
        QueryPage $page,
        Closure $extra,
    ): Builder {
        $builder = $this->queries->of($scope, $entity);

        $this->queries->search($builder, $scope, $entity, $search);

        if ($ids !== []) {
            $builder->whereIn($entity->table().'.id', $ids);
        }

        if ($updatedSince !== null) {
            $builder->where($entity->table().'.updated_at', '>=', $updatedSince);
        }

        $extra($builder);

        $this->queries->paginate($builder, $entity, $page);

        return $builder;
    }

    /**
     * One read transaction per call (ADR-002 D3), with the cancellation token
     * checked on both sides of it.
     *
     * @template TSummary
     *
     * @param  Closure(): Builder<covariant Model>  $build
     * @param  Closure(Model): TSummary  $project
     * @return list<TSummary>
     */
    private function read(CancellationToken $cancellation, Closure $build, Closure $project): array
    {
        $cancellation->throwIfCancelled();

        /** @var Collection<int, Model> $rows */
        $rows = $this->connections->connection()->transaction(static fn (): Collection => $build()->get());

        $cancellation->throwIfCancelled();

        return array_values(array_map($project, $rows->all()));
    }

    private function customerSummary(Model $model): CustomerSummary
    {
        /** @var Customer $customer */
        $customer = $model;

        return new CustomerSummary(
            id: (string) $customer->getKey(),
            organizationId: $customer->organization_id,
            name: $customer->display_name,
            legalIdentity: $this->legalIdentityOf($customer),
            ownerUserId: $customer->owner_user_id,
            status: $customer->status->value,
            updatedAt: $this->instant($customer->updated_at),
        );
    }

    private function contactSummary(Model $model): ContactSummary
    {
        /** @var Contact $contact */
        $contact = $model;

        return new ContactSummary(
            id: (string) $contact->getKey(),
            organizationId: $contact->organization_id,
            displayName: $contact->display_name,
            emailAddresses: self::strings($contact->emails->pluck('address')->all()),
            customerIds: self::sortedStrings($contact->customers->pluck('id')->all()),
            updatedAt: $this->instant($contact->updated_at),
        );
    }

    private function projectSummary(Model $model): ProjectSummary
    {
        /** @var Project $project */
        $project = $model;

        return new ProjectSummary(
            id: (string) $project->getKey(),
            organizationId: $project->organization_id,
            customerId: $project->customer_id,
            name: $project->name,
            status: $project->status->value,
            dueAt: $project->due_on?->toDateTimeImmutable(),
            updatedAt: $this->instant($project->updated_at),
        );
    }

    private function taskSummary(Model $model): TaskSummary
    {
        /** @var Task $task */
        $task = $model;

        return new TaskSummary(
            id: (string) $task->getKey(),
            organizationId: $task->organization_id,
            customerId: $task->customer_id,
            projectId: $task->project_id,
            title: $task->title,
            status: $task->status->value,
            assigneeUserIds: self::sortedStrings($task->assignees->pluck('user_id')->all()),
            dueAt: $task->due_at?->toDateTimeImmutable(),
            updatedAt: $this->instant($task->updated_at),
        );
    }

    private function activityEntry(Model $model): ActivityEntry
    {
        /** @var Communication $communication */
        $communication = $model;

        return new ActivityEntry(
            id: (string) $communication->getKey(),
            organizationId: $communication->organization_id,
            source: $communication->source_type->activitySource(),
            sourceType: $communication->source_type->value,
            // A locally typed note is its own original: it carries no provider
            // id, so the row's own id is what a brief cites (§4.2).
            sourceId: $communication->source_id ?? (string) $communication->getKey(),
            occurredAt: $communication->occurred_at->toDateTimeImmutable(),
            summary: $communication->subject,
            customerId: $communication->customer_id,
            contactId: $communication->contact_id,
            projectId: null,
        );
    }

    /**
     * §3.2's "legal identity": the registered name, with the registration
     * number where the record carries one. Null rather than an empty string, so
     * a consumer can tell "not recorded" from "recorded as blank".
     */
    private function legalIdentityOf(Customer $customer): ?string
    {
        $legalName = trim($customer->legal_name);
        $registration = trim((string) $customer->registration_number);

        if ($legalName === '') {
            return $registration === '' ? null : $customer->country.$registration;
        }

        return $registration === '' ? $legalName : "{$legalName} ({$customer->country}{$registration})";
    }

    /**
     * @param  list<ActivitySource>  $sources
     * @return list<string> the `communications.source_type` values those origins cover
     */
    private static function sourceTypesFor(array $sources): array
    {
        $wanted = array_map(static fn (ActivitySource $source): string => $source->value, $sources);
        $types = [];

        foreach (CommunicationSourceType::cases() as $type) {
            if (in_array($type->activitySource()->value, $wanted, true)) {
                $types[] = $type->value;
            }
        }

        return $types;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_map(static fn (mixed $value): string => (string) $value, $values));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function sortedStrings(array $values): array
    {
        $strings = self::strings($values);
        sort($strings);

        return $strings;
    }

    private function instant(?CarbonInterface $value): DateTimeImmutable
    {
        return ($value ?? now())->toDateTimeImmutable();
    }
}
