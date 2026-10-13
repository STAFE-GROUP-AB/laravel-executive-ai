<?php

declare(strict_types=1);

namespace App\Livewire\Crm;

use App\CRM\Deletions\CrmDeletionRefused;
use App\CRM\Deletions\CrmDeletions;
use App\CRM\Eloquent\CrmEntity;
use App\CRM\Eloquent\CrmRepository;
use App\CRM\Eloquent\CurrentWorkspace;
use App\CRM\Exceptions\WorkspaceAccessDeniedException;
use App\CRM\WorkspaceScope;
use App\Models\Approvals\ActionApproval;
use App\Models\Approvals\ActionProposal;
use App\Models\CRM\CrmModel;
use App\Security\Authorization\Gates\Abilities;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What the four §1.2 CRM screens have in common: the list, the search, the
 * filters, the one form panel that both creates and edits, and the §11
 * approval-driven deletion. A subclass says which record type it is, what its
 * form holds and how a form becomes attributes — nothing else.
 *
 * ## Every action authorizes, and the ability is named
 *
 * `docs/architecture/authorization.md` §6 lists the Livewire action as one of
 * the five T-73 surfaces, and `PolicySurfaceArchTest` fails the build for an
 * action that runs no named check. The abilities here are not decoration:
 *
 * - the page itself is `viewCrm`, which answers "is this a signed-in user of
 *   this install" and deliberately **not** "is this a member of an
 *   organization" — a session without a membership gets a 200 and the empty
 *   states (pwa.md §2);
 * - every record action puts a **row** to the policy — `view`, `create`,
 *   `update`, `delete`, `restore` — so the tenant decision is `CustomerPolicy`
 *   and friends, made on the row's own `organization_id`, with an audit row for
 *   every refusal and every mutation (T-46);
 * - a record this workspace does not have is a 404 and not a 403, because
 *   `CrmRepository::find()` answers the same way for an unknown id and a
 *   foreign one. A screen that answered 403 for one and 404 for the other
 *   would be an existence oracle for another workspace's ULIDs.
 *
 * ## Deletion is two decisions
 *
 * §11 makes CRM deletion approval-driven, so the Delete button does not delete.
 * It freezes an `action_proposals` row; the record stays, marked as awaiting
 * approval, until somebody approves — which records an `action_approvals` row
 * with the approver's identity and the hash they saw, and only then soft-
 * deletes. `CrmDeletions` holds the mechanics and the argument.
 *
 * ## AI is never required
 *
 * Nothing on these screens reads the readiness report, dispatches a run or
 * waits for a provider. §4.2's "AI unavailable must not block the CRM" is
 * therefore structural rather than tested-in: there is no code path from a CRM
 * screen into the assistant's namespaces at all, and
 * `CrmWorksWithoutAiTest` asserts that by reading this file — which is also
 * why the class names none of those types even in prose.
 */
abstract class CrmScreen extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'customer', except: '')]
    public string $customer = '';

    #[Url(as: 'deleted', except: false)]
    public bool $showDeleted = false;

    /** The record the form is editing, or null while it creates a new one. */
    public ?string $editing = null;

    /** Whether the form panel is open at all. */
    public bool $formOpen = false;

    /** The record whose detail panel is open. */
    public ?string $viewing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?string $notice = null;

    /**
     * The error state: a refusal or a lapsed approval, said in words rather
     * than as a stack trace. Rendered as `role="alert"`.
     */
    public ?string $problem = null;

    public function mount(): void
    {
        $this->form = $this->emptyForm();
    }

    /** Filters change the result set, so the page number cannot survive them. */
    public function updating(string $property, mixed $value): void
    {
        if (in_array($property, ['search', 'status', 'customer', 'showDeleted'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        Gate::authorize(Abilities::VIEW_CRM);

        $this->reset(['search', 'status', 'customer', 'showDeleted']);
        $this->resetPage();
    }

    public function startCreate(): void
    {
        Gate::authorize(Abilities::CREATE, $this->blankRecord());

        $this->resetErrorBag();
        $this->editing = null;
        $this->viewing = null;
        $this->form = $this->emptyForm();
        $this->formOpen = true;
        $this->notice = null;
        $this->problem = null;
    }

    public function startEdit(string $id): void
    {
        $row = $this->record($id);

        Gate::authorize(Abilities::UPDATE, $row);

        $this->resetErrorBag();
        $this->editing = (string) $row->getKey();
        $this->viewing = null;
        $this->form = $this->formFor($row);
        $this->formOpen = true;
        $this->notice = null;
        $this->problem = null;
    }

    public function openRecord(string $id): void
    {
        $row = $this->record($id, trashed: true);

        Gate::authorize(Abilities::VIEW, $row);

        $this->viewing = (string) $row->getKey();
        $this->formOpen = false;
        $this->problem = null;
    }

    public function closePanels(): void
    {
        Gate::authorize(Abilities::VIEW_CRM);

        $this->resetErrorBag();
        $this->formOpen = false;
        $this->editing = null;
        $this->viewing = null;
        $this->form = $this->emptyForm();
    }

    public function save(): void
    {
        $editing = $this->editing;
        $row = $editing === null ? null : $this->record($editing);

        $row === null
            ? Gate::authorize(Abilities::CREATE, $this->blankRecord())
            : Gate::authorize(Abilities::UPDATE, $row);

        $this->validateForm();

        $scope = $this->requireScope();
        $now = CarbonImmutable::now();
        $attributes = $this->attributesFrom($this->form);

        // A new row needs the not-null columns this form does not edit; an
        // existing one must keep whatever is in them, so the defaults are
        // merged on create only. A form that wrote them every time would
        // silently clear a contact's phone numbers on an unrelated edit.
        $saved = $row === null
            ? $this->repository()->create($scope, $this->entity(), [...$this->creationDefaults(), ...$attributes], $now)
            : $this->saveExisting($row, $attributes, $now);

        $this->afterSave($scope, $saved, $this->form, $now);

        $this->notice = $row === null ? $this->createdMessage() : $this->updatedMessage();
        $this->formOpen = false;
        $this->editing = null;
        $this->viewing = (string) $saved->getKey();
        $this->form = $this->emptyForm();
        $this->problem = null;
    }

    /**
     * §11: a deletion is proposed, never performed. The record is untouched and
     * the screen shows it as awaiting approval.
     */
    public function proposeDeletion(string $id): void
    {
        $row = $this->record($id);

        Gate::authorize(Abilities::DELETE, $row);

        $this->deletions()->propose($this->requireScope(), $this->entity(), $row, CarbonImmutable::now());

        $this->notice = __('crm.deletion.proposed');
        $this->problem = null;
    }

    /**
     * The second decision: record who approved, with the hash they saw, then
     * soft-delete. Two abilities, because two things are decided — the row may
     * be deleted by this user (`delete` on the record) and an approval may be
     * written into this workspace (`create` on `action_approvals`).
     */
    public function approveDeletion(string $id): void
    {
        $row = $this->record($id);
        $scope = $this->requireScope();

        Gate::authorize(Abilities::DELETE, $row);
        Gate::authorize(Abilities::CREATE, $this->blankApproval($scope));

        try {
            $this->deletions()->approve($scope, $this->entity(), $row, $this->workspace()->user(), CarbonImmutable::now());
        } catch (CrmDeletionRefused $refused) {
            $this->problem = $refused->getMessage();
            $this->notice = null;

            return;
        }

        $this->notice = __('crm.deletion.done');
        $this->problem = null;
        $this->viewing = null;
    }

    public function rejectDeletion(string $id): void
    {
        $row = $this->record($id);

        Gate::authorize(Abilities::DELETE, $row);

        $this->deletions()->reject($this->requireScope(), $this->entity(), $row, $this->workspace()->user(), CarbonImmutable::now());

        $this->notice = __('crm.deletion.withdrawn');
        $this->problem = null;
    }

    public function restoreRecord(string $id): void
    {
        $row = $this->record($id, trashed: true);

        Gate::authorize(Abilities::RESTORE, $row);

        $this->repository()->restore($row, CarbonImmutable::now());

        $this->notice = __('crm.restored');
        $this->problem = null;
    }

    abstract protected function entity(): CrmEntity;

    /**
     * Validation rules keyed by the `form.` path they apply to, so the error
     * bag keys match the input names and `@error('form.x')` finds them.
     *
     * @return array<string, list<mixed>>
     */
    abstract protected function rules(): array;

    /** @return array<string, mixed> */
    abstract protected function emptyForm(): array;

    /** @return array<string, mixed> */
    abstract protected function formFor(CrmModel $row): array;

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    abstract protected function attributesFrom(array $form): array;

    /**
     * What the detail panel shows for one record, as label => value. Rendered
     * as a definition list, so the panel is one partial for all four screens
     * and the fields that differ are stated where they are known.
     *
     * @return array<string, string>
     */
    abstract protected function detailFields(CrmModel $row): array;

    /**
     * Columns a new row needs that this form does not edit. Merged on create
     * and never on update; see `save()`.
     *
     * @return array<string, mixed>
     */
    protected function creationDefaults(): array
    {
        return [];
    }

    /** Child rows a record owns — a contact's addresses, its customer links. */
    protected function afterSave(WorkspaceScope $scope, CrmModel $row, array $form, CarbonImmutable $now): void
    {
        //
    }

    protected function validateForm(): void
    {
        $this->validate($this->rules(), [], $this->validationLabels());
    }

    /** @return array<string, string> */
    protected function validationLabels(): array
    {
        return [];
    }

    /**
     * The page the list renders, or an empty one when the session has no
     * membership — a 200 with the empty state, not a 403 (pwa.md §2).
     *
     * @return LengthAwarePaginator<int, CrmModel>
     */
    protected function page(): LengthAwarePaginator
    {
        $scope = $this->workspace()->scope();

        if ($scope === null) {
            return $this->emptyPage();
        }

        try {
            return $this->repository()->paginate(
                $scope,
                $this->entity(),
                $this->search === '' ? null : $this->search,
                $this->status,
                $this->customer,
                $this->showDeleted,
            );
        } catch (WorkspaceAccessDeniedException) {
            // The membership was revoked between the page load and this
            // request. The honest answer is the empty state, the same one a
            // session with no membership sees — not a 403 page in place of a
            // list the person was just looking at.
            return $this->emptyPage();
        }
    }

    /**
     * The pending deletion proposals for the rows on screen, keyed by record
     * id, so a row can show "awaiting approval" without a query of its own.
     *
     * @param  LengthAwarePaginator<int, CrmModel>  $page
     * @return array<string, ActionProposal>
     */
    protected function pendingDeletions(LengthAwarePaginator $page): array
    {
        $scope = $this->workspace()->scope();

        if ($scope === null) {
            return [];
        }

        $ids = [];

        foreach ($page->items() as $row) {
            $ids[] = (string) $row->getKey();
        }

        return $this->deletions()->pendingByRecord($scope, $this->entity(), $ids, CarbonImmutable::now());
    }

    /**
     * The detail panel: the open record, the labelled fields to show for it and
     * the pending deletion proposal if there is one.
     *
     * The record is read back from the repository rather than kept in component
     * state, so what is shown is what is stored — and a stale id in the
     * client's payload cannot resurrect a row, because the read is scoped and
     * answers null.
     *
     * `hasWorkspace` rides along because the shared view needs it and a view
     * cannot call a protected method: it is false for a signed-in session with
     * no membership, which is the empty state rather than a 403 (pwa.md §2).
     *
     * @return array{hasWorkspace: bool, record: ?CrmModel, recordLabel: string, details: array<string, string>, pendingForRecord: ?ActionProposal}
     */
    protected function recordPanel(): array
    {
        $scope = $this->workspace()->scope();

        if ($scope === null) {
            return ['hasWorkspace' => false, 'record' => null, 'recordLabel' => '', 'details' => [], 'pendingForRecord' => null];
        }

        $record = $this->viewing === null
            ? null
            : $this->repository()->find($scope, $this->entity(), $this->viewing, trashed: true);

        if ($record === null) {
            return ['hasWorkspace' => true, 'record' => null, 'recordLabel' => '', 'details' => [], 'pendingForRecord' => null];
        }

        return [
            'hasWorkspace' => true,
            'record' => $record,
            'recordLabel' => (string) $record->getAttribute($this->entity()->displayColumn()),
            'details' => $this->detailFields($record),
            'pendingForRecord' => $this->deletions()->pendingFor($scope, $this->entity(), (string) $record->getKey(), CarbonImmutable::now()),
        ];
    }

    /**
     * The record, or a 404. A foreign id and an unknown id are the same
     * answer — see the class docblock.
     */
    protected function record(string $id, bool $trashed = false): CrmModel
    {
        $row = $this->repository()->find($this->requireScope(), $this->entity(), $id, $trashed);

        if (! $row instanceof CrmModel) {
            abort(404);
        }

        return $row;
    }

    /**
     * The scope an action needs. An action is a deliberate mutation of a record
     * in a workspace, so unlike a render it cannot proceed without one.
     */
    protected function requireScope(): WorkspaceScope
    {
        $scope = $this->workspace()->scope();

        if ($scope === null) {
            abort(404);
        }

        return $scope;
    }

    protected function repository(): CrmRepository
    {
        return app(CrmRepository::class);
    }

    protected function deletions(): CrmDeletions
    {
        return app(CrmDeletions::class);
    }

    protected function workspace(): CurrentWorkspace
    {
        return app(CurrentWorkspace::class);
    }

    /** @return array<string, string> status value => label, for the filter and the form */
    protected function statusOptions(): array
    {
        return [];
    }

    protected function createdMessage(): string
    {
        return __('crm.saved.created');
    }

    protected function updatedMessage(): string
    {
        return __('crm.saved.updated');
    }

    /**
     * An unsaved record carrying the workspace's organization, so
     * `BusinessObjectPolicy::create()` decides on an organization rather than
     * on nothing. A session with no membership gets a model with no
     * organization, which `Tenancy::isMember()` refuses — fail closed.
     */
    private function blankRecord(): CrmModel
    {
        $scope = $this->workspace()->scope();
        $row = $this->entity()->newModel();
        $row->organization_id = $scope === null ? '' : $scope->organizationId;

        return $row;
    }

    private function blankApproval(WorkspaceScope $scope): ActionApproval
    {
        $approval = new ActionApproval;
        $approval->organization_id = $scope->organizationId;

        return $approval;
    }

    /**
     * Not called `updated()`: that is one of Livewire's lifecycle hooks, and a
     * component method with that name is invoked through the container with
     * `($property, $value)` — which fails to resolve `CrmModel` and turns
     * every save into a container error.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function saveExisting(CrmModel $row, array $attributes, CarbonImmutable $now): CrmModel
    {
        $this->repository()->update($row, $attributes, $now);

        return $row;
    }

    /** @return LengthAwarePaginator<int, CrmModel> */
    private function emptyPage(): LengthAwarePaginator
    {
        return new Paginator([], 0, CrmRepository::PER_PAGE, 1, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }
}
