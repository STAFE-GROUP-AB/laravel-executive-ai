{{-- The customer form. Tab order follows the visual order; every field is labelled. --}}
<div class="grid gap-4 sm:grid-cols-2">
    <x-crm.field name="form.display_name" :label="__('crm.customers.fields.display_name')" required />
    <x-crm.field name="form.legal_name" :label="__('crm.customers.fields.legal_name')" required />
    <x-crm.field name="form.country" :label="__('crm.customers.fields.country')" required placeholder="SE" />
    <x-crm.field name="form.registration_number" :label="__('crm.customers.fields.registration_number')" />
    <x-crm.field name="form.website" :label="__('crm.customers.fields.website')" type="url" placeholder="https://" />
    <x-crm.field name="form.status" :label="__('crm.fields.status')" type="select" :options="$statuses" required />
    <x-crm.field
        name="form.email_domains"
        :label="__('crm.customers.fields.email_domains')"
        :hint="__('crm.customers.fields.email_domains_hint')"
    />
    <x-crm.field name="form.tags" :label="__('crm.customers.fields.tags')" :hint="__('crm.customers.fields.tags_hint')" />
</div>

<x-crm.field name="form.notes" :label="__('crm.fields.notes')" type="textarea" />
