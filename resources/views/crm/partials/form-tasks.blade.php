{{--
    The task form. Every link is optional, and the deadline has no default:
    §4.2 forbids an invented one, so an empty field stores null rather than
    "today plus a week".
--}}
<div class="grid gap-4 sm:grid-cols-2">
    <x-crm.field name="form.title" :label="__('crm.tasks.fields.title')" required />
    <x-crm.field name="form.status" :label="__('crm.fields.status')" type="select" :options="$statuses" required />
    <x-crm.field name="form.priority" :label="__('crm.tasks.fields.priority')" type="select" :options="$priorities" required />
    <x-crm.field name="form.due_at" :label="__('crm.tasks.fields.due_at')" type="datetime-local" :hint="__('crm.tasks.no_due_date')" />
    <x-crm.field
        name="form.customer_id"
        :label="__('crm.fields.customer')"
        type="select"
        :options="$customers"
        :blankOption="__('crm.fields.none')"
    />
    <x-crm.field
        name="form.project_id"
        :label="__('crm.fields.project')"
        type="select"
        :options="$projects"
        :blankOption="__('crm.fields.none')"
    />
    <x-crm.field
        name="form.contact_id"
        :label="__('crm.fields.contact')"
        type="select"
        :options="$contacts"
        :blankOption="__('crm.fields.none')"
    />
</div>

<x-crm.field name="form.description" :label="__('crm.fields.description')" type="textarea" />
