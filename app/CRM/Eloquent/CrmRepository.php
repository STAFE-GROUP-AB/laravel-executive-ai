<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\CRM\Exceptions\WorkspaceAccessDeniedException;
use App\CRM\WorkspaceScope;
use App\Models\CRM\Contact;
use App\Models\CRM\CrmModel;
use App\Models\CRM\CustomerContact;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The CRM screens' data layer.
 *
 * `EloquentWorkspaceRepository` is the frozen §3 read API the agent and the
 * brief generator share; this is the other half — the reads a list screen needs
 * (an offset page a person can navigate, options for a select) and the writes
 * §3.2 asks for. They are separate classes because they answer to different
 * contracts: that one may never grow a write, and this one may never leak an
 * Eloquent model into a prompt.
 *
 * What they share is the thing that matters: **both get every query from
 * `WorkspaceQueries::of()`**, which authorizes the scope and applies the
 * `organization_id` predicate in the same call. There is not one `where
 * organization_id` in this file. That is what "the tenant boundary is enforced
 * at the repository boundary" means in practice — not a convention the next
 * method is trusted to follow, but the only way to obtain a query at all.
 *
 * Two consequences worth stating:
 *
 * - **A foreign id and an unknown id are the same answer.** `find()` returns
 *   null for both, so the screens cannot be used to discover which ULIDs exist
 *   in another workspace (the property `TenantRowGuard` makes for the tables
 *   without a model, authorization.md §4).
 * - **Deletion is not here.** §11 makes CRM deletion approval-driven, so it
 *   lives in `CrmDeletions` and goes through a frozen proposal and a recorded
 *   approval. This class restores, because restoring destroys nothing.
 *
 * Every write stamps the §3.1 lineage: `provenance` naming the person who
 * typed it, the entity's `sensitivity_class`, and the retention columns derived
 * from the row's own rule (`RetentionStamp`).
 */
final class CrmRepository
{
    public const int PER_PAGE = 20;

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly WorkspaceQueries $queries,
        private readonly ContactEmailSync $emails,
    ) {}

    /**
     * One page of a list screen.
     *
     * Ordered by the entity's display column and then by `id`, which is a
     * *total* order on both ADR-002 D1 profiles — a page boundary cannot
     * duplicate or skip a row because two records share a name. Tasks are not
     * ordered by due date on purpose: "nulls last" has no portable spelling and
     * the screen offers a status filter instead.
     *
     * @return LengthAwarePaginator<int, CrmModel>
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function paginate(
        WorkspaceScope $scope,
        CrmEntity $entity,
        ?string $search = null,
        ?string $status = null,
        ?string $customerId = null,
        bool $trashed = false,
        int $perPage = self::PER_PAGE,
    ): LengthAwarePaginator {
        $builder = $this->queries->of($scope, $entity);

        $this->queries->search($builder, $scope, $entity, $search);

        if ($trashed) {
            $builder->onlyTrashed();
        }

        if ($status !== null && $status !== '' && $entity->hasStatus()) {
            $builder->where($entity->table().'.status', $status);
        }

        if ($customerId !== null && $customerId !== '' && $entity->hasCustomerColumn()) {
            $builder->where($entity->table().'.customer_id', $customerId);
        }

        $this->eagerLoad($builder, $entity);

        return $builder
            ->orderBy($entity->table().'.'.$entity->displayColumn())
            ->orderBy($entity->table().'.id')
            ->paginate($perPage);
    }

    /**
     * One record, or null when it is not this workspace's — the same answer for
     * an id that does not exist and an id that belongs to somebody else.
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function find(WorkspaceScope $scope, CrmEntity $entity, string $id, bool $trashed = false): ?CrmModel
    {
        $builder = $this->queries->of($scope, $entity);

        if ($trashed) {
            $builder->withTrashed();
        }

        $this->eagerLoad($builder, $entity);

        $row = $builder->whereKey($id)->first();

        return $row instanceof CrmModel ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function create(WorkspaceScope $scope, CrmEntity $entity, array $attributes, CarbonImmutable $now): CrmModel
    {
        $organizationId = $this->queries->organizationFor($scope);

        return $this->connections->connection()->transaction(function () use ($scope, $entity, $attributes, $organizationId, $now): CrmModel {
            $row = $entity->newModel();
            $row->forceFill([
                ...$attributes,
                // The organization comes from the authorized scope, never from
                // the form: a hidden field naming another workspace is not a
                // thing this code can express (ipc-contract §11.4).
                'organization_id' => $organizationId,
                'sensitivity_class' => $entity->sensitivityClass(),
                'provenance' => Provenance::user($scope->actorUserId, ProvenanceOrigin::Manual),
            ]);

            RetentionStamp::apply($row, $now);
            $row->save();

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(CrmModel $row, array $attributes, CarbonImmutable $now): void
    {
        $this->connections->connection()->transaction(function () use ($row, $attributes, $now): void {
            $row->forceFill($attributes);
            RetentionStamp::apply($row, $now);
            $row->save();
        });
    }

    /**
     * Bring a soft-deleted record back. The counterpart of `CrmDeletions` and
     * deliberately not symmetrical with it: a deletion needs an approval
     * because it removes something, and a restore needs none because it does
     * not.
     */
    public function restore(CrmModel $row, CarbonImmutable $now): void
    {
        $this->connections->connection()->transaction(function () use ($row, $now): void {
            $row->restore();
            RetentionStamp::apply($row, $now);
            $row->saveQuietly();
        });
    }

    /**
     * The addresses a contact is known by, in order (§3.2).
     *
     * @param  list<array{address: string, label: string, primary: bool}>  $addresses
     */
    public function syncContactEmails(WorkspaceScope $scope, Contact $contact, array $addresses, CarbonImmutable $now): void
    {
        $this->emails->sync($contact, $addresses, $scope->actorUserId, $now);
    }

    /**
     * The customers a contact belongs to. `customer_contact` is a business
     * object in its own right (ULID, provenance, soft delete), so links are
     * written through the model rather than with `attach()` — and a link that
     * was removed and is added again is **restored**, because the unique index
     * on `(customer_id, contact_id)` does not know about `deleted_at`.
     *
     * @param  list<string>  $customerIds
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function syncContactCustomers(WorkspaceScope $scope, Contact $contact, array $customerIds, CarbonImmutable $now): void
    {
        // Only customers this workspace actually has: an id typed into the form
        // that belongs elsewhere is dropped here rather than written and
        // refused later.
        $allowed = $this->queries->of($scope, CrmEntity::Customers)
            ->whereIn('customers.id', $customerIds)
            ->pluck('customers.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->connections->connection()->transaction(function () use ($contact, $allowed, $scope, $now): void {
            // Scoped by organization as well as by the contact: the contact
            // came from a scoped read and its links cannot belong elsewhere,
            // but `withTrashed()` bypasses enough of the normal reading that
            // the predicate is worth stating rather than deducing.
            $existing = CustomerContact::withTrashed()
                ->where('organization_id', $contact->organization_id)
                ->where('contact_id', $contact->getKey())
                ->get();

            foreach ($existing as $link) {
                $wanted = in_array((string) $link->customer_id, $allowed, true);

                if ($wanted && $link->trashed()) {
                    $link->restore();

                    continue;
                }

                if (! $wanted && ! $link->trashed()) {
                    $link->delete();
                }
            }

            $linked = $existing->pluck('customer_id')->map(static fn (mixed $id): string => (string) $id)->all();

            foreach ($allowed as $customerId) {
                if (in_array($customerId, $linked, true)) {
                    continue;
                }

                $link = new CustomerContact;
                $link->forceFill([
                    'organization_id' => $contact->organization_id,
                    'customer_id' => $customerId,
                    'contact_id' => $contact->getKey(),
                    'sensitivity_class' => $contact->sensitivity_class,
                    'provenance' => Provenance::user($scope->actorUserId, ProvenanceOrigin::Manual),
                ]);
                RetentionStamp::apply($link, $now);
                $link->save();
            }
        });
    }

    /**
     * The customers of this workspace as `id => display name`, for a select.
     * Bounded, because a select is not a list screen: past the bound the form
     * asks for an id instead of offering every row.
     *
     * @return array<string, string>
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function customerOptions(WorkspaceScope $scope, int $limit = 500): array
    {
        return $this->options($this->queries->of($scope, CrmEntity::Customers), 'display_name', $limit);
    }

    /**
     * @return array<string, string>
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function projectOptions(WorkspaceScope $scope, int $limit = 500): array
    {
        return $this->options($this->queries->of($scope, CrmEntity::Projects), 'name', $limit);
    }

    /**
     * @return array<string, string>
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function contactOptions(WorkspaceScope $scope, int $limit = 500): array
    {
        return $this->options($this->queries->of($scope, CrmEntity::Contacts), 'display_name', $limit);
    }

    /**
     * @param  Builder<CrmModel>  $builder
     * @return array<string, string>
     */
    private function options(Builder $builder, string $labelColumn, int $limit): array
    {
        $options = [];

        foreach ($builder->orderBy($labelColumn)->orderBy('id')->limit($limit)->get() as $row) {
            $options[(string) $row->getKey()] = (string) $row->getAttribute($labelColumn);
        }

        return $options;
    }

    /**
     * What a list or detail screen shows beyond the row itself. Declared here
     * so no screen renders a relation lazily inside a loop.
     *
     * @param  Builder<CrmModel>  $builder
     */
    private function eagerLoad(Builder $builder, CrmEntity $entity): void
    {
        match ($entity) {
            CrmEntity::Customers => $builder->with('owner'),
            CrmEntity::Contacts => $builder->with(['emails', 'customers']),
            CrmEntity::Projects => $builder->with('customer'),
            CrmEntity::Tasks => $builder->with(['customer', 'project', 'contact']),
        };
    }
}
