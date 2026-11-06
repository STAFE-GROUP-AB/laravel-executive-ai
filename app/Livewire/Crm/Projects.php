<?php

declare(strict_types=1);

namespace App\Livewire\Crm;

use App\CRM\Eloquent\CrmEntity;
use App\CRM\Projects\ProjectStatus;
use App\Models\CRM\CrmModel;
use App\Models\CRM\Project;
use App\Security\Authorization\Gates\Abilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * `/crm/projects` — projects per customer, with status and deadlines (§3.2).
 *
 * `starts_on` and `due_on` are **calendar dates**, not instants: a deadline on
 * 17 October is the 17th wherever the owner happens to be that week, which is
 * why the migration uses `date` and the form a `<input type="date">`. The
 * contract's `ProjectSummary::$dueAt` carries the same value.
 *
 * The customer is required and comes from a select of this workspace's
 * customers; an id for somebody else's customer is not offered and would be
 * refused by the foreign key and by the policy on save.
 */
#[Layout('layouts.page')]
#[Title('Projects')]
final class Projects extends CrmScreen
{
    public function render(): View
    {
        Gate::authorize(Abilities::VIEW_CRM);

        $page = $this->page();
        $scope = $this->workspace()->scope();

        return view('crm.projects', [
            'page' => $page,
            'pending' => $this->pendingDeletions($page),
            'customers' => $scope === null ? [] : $this->repository()->customerOptions($scope),
            'statuses' => $this->statusOptions(),
            ...$this->recordPanel(),
        ]);
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Projects;
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:200'],
            'form.customer_id' => ['required', 'string', 'size:26'],
            'form.status' => ['required', 'string', 'in:'.implode(',', array_keys($this->statusOptions()))],
            'form.starts_on' => ['nullable', 'date'],
            'form.due_on' => ['nullable', 'date', 'after_or_equal:form.starts_on'],
            'form.description' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, mixed> */
    protected function emptyForm(): array
    {
        return [
            'name' => '',
            'customer_id' => '',
            'status' => ProjectStatus::Planned->value,
            'starts_on' => '',
            'due_on' => '',
            'description' => '',
        ];
    }

    /** @return array<string, mixed> */
    protected function formFor(CrmModel $row): array
    {
        /** @var Project $project */
        $project = $row;

        return [
            'name' => $project->name,
            'customer_id' => $project->customer_id,
            'status' => $project->status->value,
            'starts_on' => $project->starts_on?->toDateString() ?? '',
            'due_on' => $project->due_on?->toDateString() ?? '',
            'description' => $project->description,
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function attributesFrom(array $form): array
    {
        return [
            'name' => trim((string) $form['name']),
            'customer_id' => (string) $form['customer_id'],
            'status' => (string) $form['status'],
            'starts_on' => self::dateOrNull((string) $form['starts_on']),
            'due_on' => self::dateOrNull((string) $form['due_on']),
            'description' => trim((string) $form['description']),
        ];
    }

    /** @return array<string, string> */
    protected function detailFields(CrmModel $row): array
    {
        /** @var Project $project */
        $project = $row;
        $customer = $project->customer;

        return [
            __('crm.fields.customer') => $customer === null ? '' : $customer->display_name,
            __('crm.fields.status') => __("crm.status.project.{$project->status->value}"),
            __('crm.projects.fields.starts_on') => $project->starts_on?->toDateString() ?? '',
            __('crm.projects.fields.due_on') => $project->due_on?->toDateString() ?? '',
            __('crm.fields.description') => $project->description,
        ];
    }

    /** @return array<string, string> */
    protected function statusOptions(): array
    {
        $options = [];

        foreach (ProjectStatus::cases() as $status) {
            $options[$status->value] = __("crm.status.project.{$status->value}");
        }

        return $options;
    }

    /** @return array<string, string> */
    protected function validationLabels(): array
    {
        return [
            'form.name' => __('crm.projects.fields.name'),
            'form.customer_id' => __('crm.fields.customer'),
            'form.status' => __('crm.fields.status'),
            'form.starts_on' => __('crm.projects.fields.starts_on'),
            'form.due_on' => __('crm.projects.fields.due_on'),
            'form.description' => __('crm.fields.description'),
        ];
    }

    private static function dateOrNull(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }
}
