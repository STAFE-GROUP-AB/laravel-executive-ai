<?php

declare(strict_types=1);

namespace App\CRM;

use App\CRM\Communications\ActivityEntry;
use App\CRM\Contacts\ContactSummary;
use App\CRM\Customers\CustomerSummary;
use App\CRM\Exceptions\WorkspaceAccessDeniedException;
use App\CRM\Projects\ProjectSummary;
use App\CRM\Queries\ActivityQuery;
use App\CRM\Queries\ContactQuery;
use App\CRM\Queries\CustomerQuery;
use App\CRM\Queries\ProjectQuery;
use App\CRM\Queries\TaskQuery;
use App\CRM\Tasks\TaskSummary;
use App\Support\Cancellation\CancellationToken;
use App\Support\Cancellation\OperationCancelledException;

/**
 * Blueprint §3, second contract: `WorkspaceRepository` with `customers()`,
 * `contacts()`, `projects()`, `tasks()` and `timeline()`. The read side of the local
 * CRM (§3.2) as seen by the brief generator, the AI tools and the `crm.*` MCP tools.
 *
 * Deliberate differences from the §3 sketch (see docs/architecture/module-contracts.md):
 * every method takes a CancellationToken; results are typed read models, not
 * Eloquent models; every query carries a WorkspaceScope and pagination.
 *
 * Contract for implementations:
 * - Rows outside `query->scope->organizationId` are never returned; an actor without
 *   membership gets WorkspaceAccessDeniedException, not an empty page.
 * - One method call runs inside one read transaction so a page is a consistent
 *   snapshot (ADR-002 D3). Writes are not part of this contract: mutations go
 *   through action proposals and approvals (§11).
 * - Results are ordered by `id` ascending (`timeline()`: `occurredAt` descending,
 *   then `id`) and bounded by `query->page`, so both database profiles return
 *   identical ids for the frozen fixture (ADR-002 D10).
 */
interface WorkspaceRepository
{
    /**
     * @return iterable<int, CustomerSummary>
     *
     * @throws WorkspaceAccessDeniedException
     * @throws OperationCancelledException
     */
    public function customers(CustomerQuery $query, CancellationToken $cancellation): iterable;

    /**
     * @return iterable<int, ContactSummary>
     *
     * @throws WorkspaceAccessDeniedException
     * @throws OperationCancelledException
     */
    public function contacts(ContactQuery $query, CancellationToken $cancellation): iterable;

    /**
     * @return iterable<int, ProjectSummary>
     *
     * @throws WorkspaceAccessDeniedException
     * @throws OperationCancelledException
     */
    public function projects(ProjectQuery $query, CancellationToken $cancellation): iterable;

    /**
     * @return iterable<int, TaskSummary>
     *
     * @throws WorkspaceAccessDeniedException
     * @throws OperationCancelledException
     */
    public function tasks(TaskQuery $query, CancellationToken $cancellation): iterable;

    /**
     * @return iterable<int, ActivityEntry>
     *
     * @throws WorkspaceAccessDeniedException
     * @throws OperationCancelledException
     */
    public function timeline(ActivityQuery $query, CancellationToken $cancellation): iterable;
}
