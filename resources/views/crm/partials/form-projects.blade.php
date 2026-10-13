{{--
    The project form. The customer is required and chosen from this workspace's
    customers only — the select is built by `CrmRepository::customerOptions()`,
    which is a scoped query, so another workspace's customer is not offerable.
--}}
<div class="grid gap-4 sm:grid-cols-2">
    <x-crm.field name="form.name" :label="__('crm.projects.fields.name')" required />
    <x-crm.field
        name="form.customer_id"
        :label="__('crm.fields.customer')"
        type="select"
        :options="$customers"
        :blankOption="__('crm.fields.none')"
        required
    />
    <x-crm.field name="form.status" :label="__('crm.fields.status')" type="select" :options="$statuses" required />
    <x-crm.field name="form.starts_on" :label="__('crm.projects.fields.starts_on')" type="date" />
    <x-crm.field name="form.due_on" :label="__('crm.projects.fields.due_on')" type="date" />
</div>

<x-crm.field name="form.description" :label="__('crm.fields.description')" type="textarea" />
