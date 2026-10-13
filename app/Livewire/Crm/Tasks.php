<?php

declare(strict_types=1);

namespace App\Livewire\Crm;

use App\Briefs\Priority;
use App\CRM\Eloquent\CrmEntity;
use App\CRM\Tasks\TaskStatus;
use App\Models\CRM\CrmModel;
use App\Models\CRM\Task;
use App\Security\Authorization\Gates\Abilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * `/crm/tasks` — todos, including the promised follow-ups §3.2 asks the
 * assistant to remember.
 *
 * Two deliberate details:
 *
 * - **`due_at` stays empty unless somebody states a deadline.** §4.2 forbids an
 *   invented one, so the field has no default and the form never fills it from
 *   "today plus a week". A task with no due date is a task with no due date.
 * - **`priority` is the brief items' vocabulary** (`App\Briefs\Priority`), so a
 *   task can be lifted into a brief unchanged rather than translated.
 *
 * A task may hang off a customer, a project, a contact, all three or none —
 * every link is optional in the schema and optional here.
 */
#[Layout('layouts.page')]
#[Title('Tasks')]
final class Tasks extends CrmScreen
{
    public function render(): View
    {
        Gate::authorize(Abilities::VIEW_CRM);

        $page = $this->page();
        $scope = $this->workspace()->scope();

        return view('crm.tasks', [
            'page' => $page,
            'pending' => $this->pendingDeletions($page),
            'customers' => $scope === null ? [] : $this->repository()->customerOptions($scope),
            'projects' => $scope === null ? [] : $this->repository()->projectOptions($scope),
            'contacts' => $scope === null ? [] : $this->repository()->contactOptions($scope),
            'statuses' => $this->statusOptions(),
            'priorities' => $this->priorityOptions(),
            ...$this->recordPanel(),
        ]);
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Tasks;
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'form.title' => ['required', 'string', 'max:200'],
            'form.status' => ['required', 'string', 'in:'.implode(',', array_keys($this->statusOptions()))],
            'form.priority' => ['required', 'string', 'in:'.implode(',', array_keys($this->priorityOptions()))],
            'form.due_at' => ['nullable', 'date'],
            'form.customer_id' => ['nullable', 'string', 'size:26'],
            'form.project_id' => ['nullable', 'string', 'size:26'],
            'form.contact_id' => ['nullable', 'string', 'size:26'],
            'form.description' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, mixed> */
    protected function emptyForm(): array
    {
        return [
            'title' => '',
            'status' => TaskStatus::Todo->value,
            'priority' => Priority::Normal->value,
            // No default: §4.2 forbids inventing a deadline.
            'due_at' => '',
            'customer_id' => '',
            'project_id' => '',
            'contact_id' => '',
            'description' => '',
        ];
    }

    /** @return array<string, mixed> */
    protected function formFor(CrmModel $row): array
    {
        /** @var Task $task */
        $task = $row;

        return [
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            // `datetime-local` wants minutes, no seconds and no offset.
            'due_at' => $task->due_at?->format('Y-m-d\TH:i') ?? '',
            'customer_id' => (string) $task->customer_id,
            'project_id' => (string) $task->project_id,
            'contact_id' => (string) $task->contact_id,
            'description' => $task->description,
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function attributesFrom(array $form): array
    {
        return [
            'title' => trim((string) $form['title']),
            'status' => (string) $form['status'],
            'priority' => (string) $form['priority'],
            'due_at' => self::blankToNull((string) $form['due_at']),
            'customer_id' => self::blankToNull((string) $form['customer_id']),
            'project_id' => self::blankToNull((string) $form['project_id']),
            'contact_id' => self::blankToNull((string) $form['contact_id']),
            'description' => trim((string) $form['description']),
        ];
    }

    /** @return array<string, string> */
    protected function detailFields(CrmModel $row): array
    {
        /** @var Task $task */
        $task = $row;
        $customer = $task->customer;
        $project = $task->project;
        $contact = $task->contact;
        $dueAt = $task->due_at;

        return [
            __('crm.fields.status') => __("crm.status.task.{$task->status->value}"),
            __('crm.tasks.fields.priority') => __("brief.priorities.{$task->priority->value}"),
            __('crm.tasks.fields.due_at') => $dueAt === null ? __('crm.tasks.no_due_date') : $dueAt->toDayDateTimeString(),
            __('crm.fields.customer') => $customer === null ? '' : $customer->display_name,
            __('crm.fields.project') => $project === null ? '' : $project->name,
            __('crm.fields.contact') => $contact === null ? '' : $contact->display_name,
            __('crm.fields.description') => $task->description,
        ];
    }

    /** @return array<string, string> */
    protected function statusOptions(): array
    {
        $options = [];

        foreach (TaskStatus::cases() as $status) {
            $options[$status->value] = __("crm.status.task.{$status->value}");
        }

        return $options;
    }

    /** @return array<string, string> */
    protected function validationLabels(): array
    {
        return [
            'form.title' => __('crm.tasks.fields.title'),
            'form.status' => __('crm.fields.status'),
            'form.priority' => __('crm.tasks.fields.priority'),
            'form.due_at' => __('crm.tasks.fields.due_at'),
            'form.customer_id' => __('crm.fields.customer'),
            'form.project_id' => __('crm.fields.project'),
            'form.contact_id' => __('crm.fields.contact'),
            'form.description' => __('crm.fields.description'),
        ];
    }

    /** @return array<string, string> */
    private function priorityOptions(): array
    {
        $options = [];

        foreach (Priority::cases() as $priority) {
            $options[$priority->value] = __("brief.priorities.{$priority->value}");
        }

        return $options;
    }

    private static function blankToNull(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }
}
